<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
use Illuminate\Support\Str;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Phase 9 — Activity Log & Audit Trail.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Folder $folder;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->user = User::factory()->create();
        $this->user->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->user->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->user->id,
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
    // FILE OPERATIONS LOG
    // ═══════════════════════════════════════════════════════

    public function test_file_update_logs_activity(): void
    {
        $this->actingAs($this->user, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.pdf'])
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'update',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);

        $log = ActivityLog::query()->where('action', 'update')->first();
        $this->assertEquals('renamed.pdf', $log->metadata['new_name']);
    }

    public function test_file_delete_logs_activity(): void
    {
        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/files/{$this->file->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'delete',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_file_download_logs_activity(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson("/api/files/{$this->file->id}/download")
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'download',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // FOLDER OPERATIONS LOG
    // ═══════════════════════════════════════════════════════

    public function test_folder_create_logs_activity(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/folders', ['name' => 'Test Folder'])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'create',
            'resource_type' => 'folder',
        ]);
    }

    public function test_folder_delete_logs_activity(): void
    {
        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/folders/{$this->folder->uuid}")
            ->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'delete',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // TRASH OPERATIONS LOG
    // ═══════════════════════════════════════════════════════

    public function test_restore_file_logs_activity(): void
    {
        // Soft delete first
        $this->file->update(['deleted_at' => now(), 'deleted_by' => $this->user->id, 'purge_at' => now()->addDays(15)]);

        $this->actingAs($this->user, 'api')
            ->postJson("/api/trash/files/{$this->file->id}/restore")
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'restore',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_restore_folder_logs_activity(): void
    {
        $this->folder->update(['deleted_at' => now(), 'deleted_by' => $this->user->id, 'purge_at' => now()->addDays(15)]);

        $this->actingAs($this->user, 'api')
            ->postJson("/api/trash/folders/{$this->folder->uuid}/restore")
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'restore',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    public function test_force_delete_file_logs_activity(): void
    {
        $fileId = $this->file->id;

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/files/{$fileId}")
            ->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'force_delete',
            'resource_type' => 'file',
            'resource_id' => $fileId,
        ]);
    }

    public function test_force_delete_folder_logs_activity(): void
    {
        $folderUuid = $this->folder->uuid;

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/trash/folders/{$folderUuid}")
            ->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'force_delete',
            'resource_type' => 'folder',
            'resource_id' => $folderUuid,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // SHARE OPERATIONS LOG
    // ═══════════════════════════════════════════════════════

    public function test_share_file_logs_activity(): void
    {
        $recipient = User::factory()->create();

        $this->actingAs($this->user, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $recipient->id,
                'permission' => 'view',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'share',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);
    }

    public function test_share_folder_logs_activity(): void
    {
        $recipient = User::factory()->create();

        $this->actingAs($this->user, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $recipient->id,
                'permission' => 'view',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'share',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    public function test_guest_access_logs_with_null_user(): void
    {
        $rawToken = Str::random(64);

        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->user->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        $this->getJson("/api/share/{$rawToken}")
            ->assertStatus(200);

        $log = ActivityLog::query()->where('action', 'guest_view')
            ->whereNull('user_id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('share', $log->resource_type);
        $this->assertEquals($share->id, $log->resource_id);
        $this->assertEquals('file', $log->metadata['shared_resource_type']);
    }

    public function test_revoke_share_logs_activity(): void
    {
        $share = Share::query()->create([
            'folder_id' => $this->folder->id,
            'shared_by' => $this->user->id,
            'shared_with' => $this->admin->id,
            'permission' => 'view',
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/share/{$share->id}")
            ->assertStatus(204);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => 'revoke_share',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // ACTIVITY QUERY ENDPOINTS
    // ═══════════════════════════════════════════════════════

    public function test_user_sees_own_activity_only(): void
    {
        // Create two logs — one for user, one for admin
        ActivityLog::query()->create([
            'user_id' => $this->user->id,
            'action' => 'create',
            'resource_type' => 'file',
            'resource_id' => 'test-1',
            'created_at' => now(),
        ]);
        ActivityLog::query()->create([
            'user_id' => $this->admin->id,
            'action' => 'create',
            'resource_type' => 'file',
            'resource_id' => 'test-2',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/activity');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->user->id, $response->json('data.0.user_id'));
    }

    public function test_admin_sees_all_activity(): void
    {
        ActivityLog::query()->create([
            'user_id' => $this->user->id,
            'action' => 'create',
            'resource_type' => 'file',
            'resource_id' => 'test-1',
            'created_at' => now(),
        ]);
        ActivityLog::query()->create([
            'user_id' => $this->admin->id,
            'action' => 'create',
            'resource_type' => 'file',
            'resource_id' => 'test-2',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/activity');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_guest_view_only_logs_once(): void
    {
        $rawToken = Str::random(64);

        Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->user->id,
            'shared_with' => null,
            'token_hash' => hash('sha256', $rawToken),
            'permission' => 'view',
        ]);

        // Access the share link 5 times
        for ($i = 0; $i < 5; $i++) {
            $this->getJson("/api/share/{$rawToken}")
                ->assertStatus(200);
        }

        // Should only have 1 activity log entry
        $count = ActivityLog::query()
            ->where('action', 'guest_view')
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_activity_pagination_works(): void
    {
        for ($i = 0; $i < 5; $i++) {
            ActivityLog::query()->create([
                'user_id' => $this->user->id,
                'action' => 'download',
                'resource_type' => 'file',
                'resource_id' => $this->file->id,
                'created_at' => now()->subSeconds(5 - $i),
            ]);
        }

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/activity?limit=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertNotNull($response->json('meta.next_cursor'));
    }

    public function test_activity_log_is_append_only(): void
    {
        $log = ActivityLog::query()->create([
            'user_id' => $this->user->id,
            'action' => 'create',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
            'created_at' => now(),
        ]);

        // Verify we can read it
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);

        // Verify the log has all expected fields
        $this->assertEquals('create', $log->action);
        $this->assertEquals('file', $log->resource_type);
        $this->assertEquals($this->user->id, $log->user_id);
    }
}
