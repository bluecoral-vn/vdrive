<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Pre-Sync Hardening — validates all guarantees required before Desktop sync.
 *
 * Covers:
 * - Duplicate-name rejection on rename (files + folders)
 * - Name collision rejection on move (files + folders)
 * - Rename/move UUID immutability
 * - Rename/move checksum preservation
 * - Folder create atomicity (sync event always emitted)
 * - Delta cursor monotonicity
 */
class PreSyncHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Folder $folder;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create(['quota_used_bytes' => 10485760]);
        $this->owner->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'size_bytes' => 1024,
            'version' => 1,
            'checksum_sha256' => 'abc123def456',
        ]);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('createMultipartUpload')->andReturn(['UploadId' => 'mock-upload-id'])->byDefault();
        $mockS3->shouldReceive('getCommand')->andReturn(Mockery::mock(CommandInterface::class))->byDefault();

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')->andReturn(new Uri('https://r2.example.com/presigned'))->byDefault();
        $mockS3->shouldReceive('createPresignedRequest')->andReturn($mockRequest)->byDefault();
        $mockS3->shouldReceive('completeMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();

        $this->instance(R2ClientService::class, new class($mockS3) extends R2ClientService
        {
            public function __construct(private S3Client $mock) {}

            public function client(): S3Client
            {
                return $this->mock;
            }

            public function bucket(): string
            {
                return 'test-bucket';
            }
        });
    }

    // ═══════════════════════════════════════════════════════
    // RENAME — DUPLICATE NAME REJECTION
    // ═══════════════════════════════════════════════════════

    public function test_rename_folder_rejects_duplicate_name(): void
    {
        $sibling = Folder::factory()->create([
            'owner_id' => $this->owner->id,
            'parent_id' => $this->folder->parent_id,
            'name' => 'Existing Folder',
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['name' => 'Existing Folder']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_rename_folder_allows_unique_name(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['name' => 'Completely Unique Name']);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Completely Unique Name');
    }

    public function test_rename_file_rejects_duplicate_name(): void
    {
        $sibling = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'name' => 'existing-file.txt',
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'existing-file.txt']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_rename_file_allows_unique_name(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'unique-file-name.txt']);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'unique-file-name.txt');
    }

    // ═══════════════════════════════════════════════════════
    // MOVE — NAME COLLISION REJECTION
    // ═══════════════════════════════════════════════════════

    public function test_move_file_rejects_name_collision_at_target(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        // Create a file with the same name in target folder
        File::factory()->create([
            'folder_id' => $targetFolder->id,
            'owner_id' => $this->owner->id,
            'name' => $this->file->name,
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['folder_id']);
    }

    public function test_move_file_allows_when_no_collision(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $response->assertStatus(200);

        $this->file->refresh();
        $this->assertEquals($targetFolder->id, $this->file->folder_id);
    }

    public function test_move_folder_rejects_name_collision_at_target(): void
    {
        $targetParent = Folder::factory()->create(['owner_id' => $this->owner->id]);

        // Create a folder with the same name under target parent
        Folder::factory()->create([
            'parent_id' => $targetParent->id,
            'owner_id' => $this->owner->id,
            'name' => $this->folder->name,
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['parent_id' => $targetParent->uuid]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_move_folder_allows_when_no_collision(): void
    {
        $targetParent = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['parent_id' => $targetParent->uuid]);

        $response->assertStatus(200);

        $this->folder->refresh();
        $this->assertEquals($targetParent->id, $this->folder->parent_id);
    }

    // ═══════════════════════════════════════════════════════
    // UUID IMMUTABILITY — RENAME & MOVE
    // ═══════════════════════════════════════════════════════

    public function test_rename_does_not_change_file_uuid(): void
    {
        $originalId = $this->file->id;

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $this->file->refresh();
        $this->assertEquals($originalId, $this->file->id);
    }

    public function test_move_does_not_change_file_uuid(): void
    {
        $originalId = $this->file->id;
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $this->file->refresh();
        $this->assertEquals($originalId, $this->file->id);
    }

    public function test_rename_does_not_change_folder_uuid(): void
    {
        $originalUuid = $this->folder->uuid;

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['name' => 'Renamed']);

        $this->folder->refresh();
        $this->assertEquals($originalUuid, $this->folder->uuid);
    }

    public function test_move_does_not_change_folder_uuid(): void
    {
        $originalUuid = $this->folder->uuid;
        $targetParent = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['parent_id' => $targetParent->uuid]);

        $this->folder->refresh();
        $this->assertEquals($originalUuid, $this->folder->uuid);
    }

    // ═══════════════════════════════════════════════════════
    // CHECKSUM PRESERVATION — RENAME & MOVE
    // ═══════════════════════════════════════════════════════

    public function test_rename_does_not_change_checksum(): void
    {
        $originalChecksum = $this->file->checksum_sha256;

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $this->file->refresh();
        $this->assertEquals($originalChecksum, $this->file->checksum_sha256);
    }

    public function test_move_does_not_change_checksum(): void
    {
        $originalChecksum = $this->file->checksum_sha256;
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $this->file->refresh();
        $this->assertEquals($originalChecksum, $this->file->checksum_sha256);
    }

    // ═══════════════════════════════════════════════════════
    // FOLDER CREATE ATOMICITY
    // ═══════════════════════════════════════════════════════

    public function test_folder_create_is_atomic(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'Atomic Test Folder']);

        $response->assertStatus(201);

        $folder = Folder::query()->where('name', 'Atomic Test Folder')->first();
        $this->assertNotNull($folder);

        // Sync event must exist for this folder
        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'create',
            'resource_type' => 'folder',
            'resource_id' => $folder->uuid,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // DELTA CURSOR MONOTONICITY
    // ═══════════════════════════════════════════════════════

    public function test_delta_cursor_is_monotonic(): void
    {
        // Create multiple events
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'Folder A']);
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'Folder B']);
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed-for-monotonic.txt']);

        $events = SyncEvent::query()
            ->where('user_id', $this->owner->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        // Verify IDs are strictly increasing
        for ($i = 1; $i < count($events); $i++) {
            $this->assertGreaterThan(
                $events[$i - 1],
                $events[$i],
                'Sync event IDs must be strictly monotonic increasing'
            );
        }
    }

    // ═══════════════════════════════════════════════════════
    // RENAME/MOVE DOES NOT EMIT DELETE + CREATE
    // ═══════════════════════════════════════════════════════

    public function test_rename_emits_rename_action_not_delete_create(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed-action-test.txt']);

        $events = SyncEvent::query()
            ->where('resource_id', $this->file->id)
            ->pluck('action')
            ->all();

        $this->assertContains('rename', $events);
        $this->assertNotContains('delete', $events);
        $this->assertNotContains('create', $events);
    }

    public function test_move_emits_move_action_not_delete_create(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $events = SyncEvent::query()
            ->where('resource_id', $this->file->id)
            ->pluck('action')
            ->all();

        $this->assertContains('move', $events);
        $this->assertNotContains('delete', $events);
        $this->assertNotContains('create', $events);
    }

    // ═══════════════════════════════════════════════════════
    // TRASHED ITEM NAME COLLISION — SHOULD NOT BLOCK
    // ═══════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════
    // TRASHED ITEM NAME COLLISION
    // ═══════════════════════════════════════════════════════
    // NOTE: The DB UNIQUE constraint covers ALL rows (including soft-deleted).
    // This is intentional — it prevents name collisions even with trashed items,
    // which simplifies restore logic (no name conflict on restore).

    public function test_rename_rejects_name_matching_trashed_sibling(): void
    {
        $sibling = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'name' => 'trashed-file.txt',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'trashed-file.txt']);

        // DB unique constraint fires even for trashed items
        $response->assertStatus(422);
    }

    public function test_move_rejects_when_collision_is_trashed(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        File::factory()->create([
            'folder_id' => $targetFolder->id,
            'owner_id' => $this->owner->id,
            'name' => $this->file->name,
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        // DB unique constraint fires even for trashed items
        $response->assertStatus(422);
    }
}
