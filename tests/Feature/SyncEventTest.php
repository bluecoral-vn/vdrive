<?php

namespace Tests\Feature;

use App\Jobs\EmptyTrashJob;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\R2ClientService;
use App\Services\SyncEventService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Phase 15 — Delta Sync Foundation tests.
 */
class SyncEventTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $stranger;

    private Folder $folder;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create(['quota_used_bytes' => 10485760]);
        $this->owner->roles()->attach($userRole);

        $this->stranger = User::factory()->create();
        $this->stranger->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'size_bytes' => 1048576,
            'version' => 1,
        ]);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);

        $mockS3->shouldReceive('createMultipartUpload')
            ->andReturn(['UploadId' => 'mock-upload-id'])
            ->byDefault();

        $mockS3->shouldReceive('getCommand')
            ->andReturn(Mockery::mock(CommandInterface::class))
            ->byDefault();

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')
            ->andReturn(new Uri('https://r2.example.com/presigned'))
            ->byDefault();

        $mockS3->shouldReceive('createPresignedRequest')
            ->andReturn($mockRequest)
            ->byDefault();

        $mockS3->shouldReceive('completeMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('abortMultipartUpload')->andReturn([])->byDefault();
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
    // FILE VERSION
    // ═══════════════════════════════════════════════════════

    public function test_file_version_defaults_to_1(): void
    {
        $this->assertEquals(1, $this->file->version);
    }

    public function test_file_version_incremented_on_rename(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $this->file->refresh();
        $this->assertEquals(2, $this->file->version);
    }

    public function test_file_version_incremented_on_move(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $this->file->refresh();
        $this->assertEquals(2, $this->file->version);
    }

    public function test_file_version_incremented_on_soft_delete(): void
    {
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}");

        $this->file->refresh();
        $this->assertEquals(2, $this->file->version);
    }

    public function test_file_version_exposed_in_api_response(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->getJson("/api/files/{$this->file->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.version', 1)
            ->assertJsonStructure(['data' => ['version', 'checksum_sha256']]);
    }

    // ═══════════════════════════════════════════════════════
    // SYNC EVENTS — FILE
    // ═══════════════════════════════════════════════════════

    public function test_sync_event_emitted_on_file_rename(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'rename',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_sync_event_emitted_on_file_move(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'move',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_sync_event_emitted_on_file_soft_delete(): void
    {
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}");

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'delete',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_sync_event_emitted_on_file_restore(): void
    {
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}");

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/trash/files/{$this->file->id}/restore");

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'restore',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_sync_event_emitted_on_file_force_delete(): void
    {
        $fileId = $this->file->id;

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$fileId}");

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'purge',
            'resource_type' => 'file',
            'resource_id' => $fileId,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // SYNC EVENTS — FOLDER
    // ═══════════════════════════════════════════════════════

    public function test_sync_event_emitted_on_folder_create(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'New Folder']);

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'create',
            'resource_type' => 'folder',
        ]);
    }

    public function test_sync_event_emitted_on_folder_rename(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['name' => 'Renamed Folder']);

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'rename',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    public function test_sync_event_emitted_on_folder_move(): void
    {
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/folders/{$this->folder->uuid}", ['parent_id' => $targetFolder->uuid]);

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'move',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    public function test_sync_events_emitted_on_folder_soft_delete_cascades(): void
    {
        $childFolder = Folder::factory()->create([
            'parent_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);
        File::factory()->create([
            'folder_id' => $childFolder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/folders/{$this->folder->uuid}");

        // Should have delete events for both folders
        $folderDeleteEvents = SyncEvent::query()
            ->where('user_id', $this->owner->id)
            ->where('action', 'delete')
            ->where('resource_type', 'folder')
            ->count();

        $this->assertGreaterThanOrEqual(2, $folderDeleteEvents);

        // Should have delete events for files too
        $fileDeleteEvents = SyncEvent::query()
            ->where('user_id', $this->owner->id)
            ->where('action', 'delete')
            ->where('resource_type', 'file')
            ->count();

        $this->assertGreaterThanOrEqual(1, $fileDeleteEvents);
    }

    public function test_sync_events_emitted_on_folder_restore_cascades(): void
    {
        $childFolder = Folder::factory()->create([
            'parent_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        // Soft delete then restore
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/folders/{$this->folder->uuid}");

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/trash/folders/{$this->folder->uuid}/restore");

        $restoreEvents = SyncEvent::query()
            ->where('user_id', $this->owner->id)
            ->where('action', 'restore')
            ->where('resource_type', 'folder')
            ->count();

        $this->assertGreaterThanOrEqual(2, $restoreEvents);
    }

    public function test_sync_events_emitted_on_bulk_move(): void
    {
        $file2 = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/move', [
                'files' => [$this->file->id, $file2->id],
                'folders' => [],
                'target_folder_id' => $targetFolder->uuid,
            ]);

        $moveEvents = SyncEvent::query()
            ->where('user_id', $this->owner->id)
            ->where('action', 'move')
            ->where('resource_type', 'file')
            ->count();

        $this->assertEquals(2, $moveEvents);
    }

    // ═══════════════════════════════════════════════════════
    // ENRICHED METADATA
    // ═══════════════════════════════════════════════════════

    public function test_rename_event_contains_old_name_in_metadata(): void
    {
        $originalName = $this->file->name;

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'new_name.txt']);

        $event = SyncEvent::query()
            ->where('action', 'rename')
            ->where('resource_type', 'file')
            ->where('resource_id', $this->file->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('new_name.txt', $event->metadata['name']);
        $this->assertEquals($originalName, $event->metadata['old_name']);
        $this->assertEquals('file', $event->metadata['resource_type']);
    }

    public function test_move_event_contains_parent_ids_in_metadata(): void
    {
        $oldFolderUuid = $this->folder->uuid;
        $targetFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);

        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['folder_id' => $targetFolder->uuid]);

        $event = SyncEvent::query()
            ->where('action', 'move')
            ->where('resource_type', 'file')
            ->where('resource_id', $this->file->id)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals($this->file->name, $event->metadata['name']);
        $this->assertEquals($oldFolderUuid, $event->metadata['old_parent_id']);
        $this->assertEquals($targetFolder->uuid, $event->metadata['new_parent_id']);
        $this->assertEquals('file', $event->metadata['resource_type']);
    }

    public function test_all_sync_events_contain_resource_type_in_metadata(): void
    {
        // Generate events: rename, delete
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}");

        $events = SyncEvent::query()
            ->where('user_id', $this->owner->id)
            ->get();

        foreach ($events as $event) {
            $this->assertArrayHasKey('resource_type', $event->metadata ?? [],
                "Event action={$event->action} resource_type={$event->resource_type} should have resource_type in metadata"
            );
        }
    }

    // ═══════════════════════════════════════════════════════
    // UPDATED_AT ON BATCH OPERATIONS
    // ═══════════════════════════════════════════════════════

    public function test_updated_at_set_on_batch_soft_delete(): void
    {
        $childFolder = Folder::factory()->create([
            'parent_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);

        $beforeDelete = now()->subMinute();

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/folders/{$this->folder->uuid}");

        $childFolder->refresh();
        $this->assertTrue(
            $childFolder->updated_at->isAfter($beforeDelete),
            'updated_at should be set on batch soft-delete descendants'
        );
    }

    // ═══════════════════════════════════════════════════════
    // DELTA API
    // ═══════════════════════════════════════════════════════

    public function test_delta_returns_events_after_cursor(): void
    {
        // Generate some events
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/sync/delta?cursor=0');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'action', 'resource_type', 'resource_id', 'metadata', 'created_at']],
                'meta' => ['next_cursor', 'has_more'],
            ]);

        $this->assertNotEmpty($response->json('data'));
        $this->assertGreaterThan(0, $response->json('meta.next_cursor'));
    }

    public function test_delta_respects_user_isolation(): void
    {
        // Owner generates an event
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        // Stranger should see no events
        $response = $this->actingAs($this->stranger, 'api')
            ->getJson('/api/sync/delta?cursor=0');

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    public function test_delta_pagination(): void
    {
        // Create multiple events
        for ($i = 0; $i < 5; $i++) {
            app(SyncEventService::class)->record(
                $this->owner->id,
                'update',
                'file',
                (string) $this->file->id,
                ['iteration' => $i],
            );
        }

        // Request with small limit
        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/sync/delta?cursor=0&limit=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertTrue($response->json('meta.has_more'));

        // Follow the cursor for next page
        $nextCursor = $response->json('meta.next_cursor');
        $response2 = $this->actingAs($this->owner, 'api')
            ->getJson("/api/sync/delta?cursor={$nextCursor}&limit=2");

        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json('data'));
    }

    public function test_status_returns_latest_cursor(): void
    {
        // Generate an event
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/sync/status');

        $response->assertStatus(200)
            ->assertJsonStructure(['latest_cursor', 'total_events']);

        $this->assertGreaterThan(0, $response->json('latest_cursor'));
        $this->assertGreaterThan(0, $response->json('total_events'));
    }

    // ═══════════════════════════════════════════════════════
    // EMPTY TRASH JOB — SYNC EVENTS
    // ═══════════════════════════════════════════════════════

    public function test_empty_trash_job_emits_purge_events(): void
    {
        // Soft delete the file
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}");

        // Clear delete event so we can check purge events separately
        SyncEvent::query()->where('action', 'delete')->delete();

        // Run the empty trash job
        $job = new EmptyTrashJob($this->owner->id);
        $job->handle(app(QuotaService::class), app(SyncEventService::class));

        $this->assertDatabaseHas('sync_events', [
            'user_id' => $this->owner->id,
            'action' => 'purge',
            'resource_type' => 'file',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // TRANSACTIONAL SAFETY
    // ═══════════════════════════════════════════════════════

    public function test_soft_delete_folder_is_atomic(): void
    {
        $childFolder = Folder::factory()->create([
            'parent_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);
        $childFile = File::factory()->create([
            'folder_id' => $childFolder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/folders/{$this->folder->uuid}")
            ->assertStatus(204);

        // All items should be trashed
        $this->folder->refresh();
        $childFolder->refresh();
        $childFile->refresh();
        $this->file->refresh();

        $this->assertTrue($this->folder->isTrashed());
        $this->assertTrue($childFolder->isTrashed());
        $this->assertTrue($childFile->isTrashed());
        $this->assertTrue($this->file->isTrashed());
    }
}
