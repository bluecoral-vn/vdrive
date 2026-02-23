<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
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
 * Phase 20 — Enterprise Permission Standard Enforcement.
 *
 * Validates that:
 * - view share = read-only (no mutations)
 * - edit share = full mutation within shared subtree
 * - owner always has full access
 * - strangers are denied all access
 */
class SharePermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private User $stranger;

    private Folder $sharedFolder;

    private Folder $childFolder;

    private File $fileInFolder;

    private File $fileInChild;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->recipient = User::factory()->create();
        $this->recipient->roles()->attach($userRole);

        $this->stranger = User::factory()->create();
        $this->stranger->roles()->attach($userRole);

        // Structure: sharedFolder > childFolder > fileInChild
        //                         > fileInFolder
        $this->sharedFolder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->fileInFolder = File::factory()->create([
            'folder_id' => $this->sharedFolder->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->childFolder = Folder::factory()->create([
            'parent_id' => $this->sharedFolder->id,
            'owner_id' => $this->owner->id,
        ]);
        $this->fileInChild = File::factory()->create([
            'folder_id' => $this->childFolder->id,
            'owner_id' => $this->owner->id,
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
    // VIEW PERMISSION — READ-ONLY (NO MUTATIONS)
    // ═══════════════════════════════════════════════════════

    public function test_view_shared_user_can_view_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->fileInFolder->id}")
            ->assertStatus(200);
    }

    public function test_view_shared_user_can_download_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->fileInFolder->id}/download")
            ->assertStatus(200);
    }

    public function test_view_shared_user_can_preview_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->getJson("/api/files/{$this->fileInFolder->id}/preview")
            ->assertStatus(200);
    }

    public function test_view_shared_user_cannot_rename_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", ['name' => 'hacked.txt'])
            ->assertStatus(403);
    }

    public function test_view_shared_user_cannot_delete_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->deleteJson("/api/files/{$this->fileInFolder->id}")
            ->assertStatus(403);
    }

    public function test_view_shared_user_cannot_move_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $recipientFolder = Folder::factory()->create(['owner_id' => $this->recipient->id]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", [
                'folder_id' => $recipientFolder->uuid,
            ])
            ->assertStatus(403);
    }

    public function test_view_shared_user_cannot_upload_to_shared_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'hack.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
                'folder_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(403);
    }

    public function test_view_shared_user_cannot_create_subfolder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->postJson('/api/folders', [
                'name' => 'hacked-subfolder',
                'parent_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(403);
    }

    public function test_view_shared_user_cannot_rename_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/folders/{$this->childFolder->uuid}", ['name' => 'hacked'])
            ->assertStatus(403);
    }

    public function test_view_shared_user_cannot_delete_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->deleteJson("/api/folders/{$this->childFolder->uuid}")
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // EDIT PERMISSION — FULL MUTATION
    // ═══════════════════════════════════════════════════════

    public function test_edit_shared_user_can_rename_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", ['name' => 'renamed.txt'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'renamed.txt');
    }

    public function test_edit_shared_user_can_delete_file(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->deleteJson("/api/files/{$this->fileInFolder->id}")
            ->assertStatus(204);
    }

    public function test_edit_shared_user_can_upload_to_shared_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'new-file.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
                'folder_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(201);
    }

    public function test_edit_shared_user_can_create_subfolder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->postJson('/api/folders', [
                'name' => 'new-subfolder',
                'parent_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(201);
    }

    public function test_edit_shared_user_can_rename_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/folders/{$this->childFolder->uuid}", ['name' => 'renamed-folder'])
            ->assertStatus(200);
    }

    public function test_edit_shared_user_can_delete_folder(): void
    {
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->deleteJson("/api/folders/{$this->childFolder->uuid}")
            ->assertStatus(204);
    }

    // ═══════════════════════════════════════════════════════
    // OWNER ALWAYS HAS FULL ACCESS
    // ═══════════════════════════════════════════════════════

    public function test_owner_can_rename_own_file(): void
    {
        $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", ['name' => 'owner-renamed.txt'])
            ->assertStatus(200);
    }

    public function test_owner_can_delete_own_file(): void
    {
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->fileInFolder->id}")
            ->assertStatus(204);
    }

    public function test_owner_can_create_subfolder(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', [
                'name' => 'owner-subfolder',
                'parent_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(201);
    }

    public function test_owner_can_upload_to_own_folder(): void
    {
        $this->actingAs($this->owner, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'owner-file.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
                'folder_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(201);
    }

    // ═══════════════════════════════════════════════════════
    // INHERITED EDIT PERMISSION
    // ═══════════════════════════════════════════════════════

    public function test_inherited_edit_share_grants_edit_to_child_file(): void
    {
        // Share parent folder with edit
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Recipient can rename file in child folder (inherited edit)
        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/files/{$this->fileInChild->id}", ['name' => 'inherited-edit.txt'])
            ->assertStatus(200);
    }

    public function test_inherited_edit_share_grants_edit_to_child_folder(): void
    {
        // Share parent folder with edit
        Share::query()->create([
            'folder_id' => $this->sharedFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        // Recipient can rename child folder (inherited edit)
        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/folders/{$this->childFolder->uuid}", ['name' => 'inherited-edit-folder'])
            ->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════
    // STRANGER DENIED
    // ═══════════════════════════════════════════════════════

    public function test_stranger_cannot_rename_file(): void
    {
        $this->actingAs($this->stranger, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", ['name' => 'stranger.txt'])
            ->assertStatus(403);
    }

    public function test_stranger_cannot_delete_file(): void
    {
        $this->actingAs($this->stranger, 'api')
            ->deleteJson("/api/files/{$this->fileInFolder->id}")
            ->assertStatus(403);
    }

    public function test_stranger_cannot_create_subfolder(): void
    {
        $this->actingAs($this->stranger, 'api')
            ->postJson('/api/folders', [
                'name' => 'stranger-subfolder',
                'parent_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(403);
    }

    public function test_stranger_cannot_upload_to_folder(): void
    {
        $this->actingAs($this->stranger, 'api')
            ->postJson('/api/upload/init', [
                'filename' => 'stranger.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 100,
                'folder_id' => $this->sharedFolder->uuid,
            ])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // DIRECT FILE SHARE — EDIT
    // ═══════════════════════════════════════════════════════

    public function test_direct_file_edit_share_allows_rename(): void
    {
        Share::query()->create([
            'file_id' => $this->fileInFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'edit',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", ['name' => 'direct-edit.txt'])
            ->assertStatus(200);
    }

    public function test_direct_file_view_share_denies_rename(): void
    {
        Share::query()->create([
            'file_id' => $this->fileInFolder->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->recipient, 'api')
            ->patchJson("/api/files/{$this->fileInFolder->id}", ['name' => 'direct-view.txt'])
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════
    // USER CAN STILL CREATE ROOT FOLDERS
    // ═══════════════════════════════════════════════════════

    public function test_any_user_can_create_root_folder(): void
    {
        $this->actingAs($this->recipient, 'api')
            ->postJson('/api/folders', ['name' => 'my-root-folder'])
            ->assertStatus(201);
    }
}
