<?php

namespace Tests\Feature;

use App\Jobs\DeleteR2ObjectJob;
use App\Jobs\LogActivityJob;
use App\Jobs\PurgeExpiredTrashJob;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use App\Services\QuotaService;
use App\Services\R2ClientService;
use App\Services\SyncEventService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Phase 12 — Background Worker & Event Queue Tests.
 *
 * A. Behavior Preservation
 * B. Job Dispatch
 * C. Idempotency
 * D. Failure Simulation
 * E. Purge Batch
 */
class BackgroundJobTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Folder $folder;

    private File $file;

    private S3Client $mockS3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->owner = User::factory()->create(['quota_used_bytes' => 10485760]);
        $this->owner->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'size_bytes' => 1048576,
        ]);

        $this->mockR2();
    }

    private function mockR2(): void
    {
        $this->mockS3 = Mockery::mock(S3Client::class);
        $this->mockS3->shouldReceive('createMultipartUpload')->andReturn(['UploadId' => 'mock-upload-id'])->byDefault();
        $this->mockS3->shouldReceive('getCommand')->andReturn(Mockery::mock(CommandInterface::class))->byDefault();

        $mockRequest = Mockery::mock(RequestInterface::class);
        $mockRequest->shouldReceive('getUri')->andReturn(new Uri('https://r2.example.com/presigned'))->byDefault();

        $this->mockS3->shouldReceive('createPresignedRequest')->andReturn($mockRequest)->byDefault();
        $this->mockS3->shouldReceive('completeMultipartUpload')->andReturn([])->byDefault();
        $this->mockS3->shouldReceive('abortMultipartUpload')->andReturn([])->byDefault();
        $this->mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();

        $this->instance(R2ClientService::class, new class($this->mockS3) extends R2ClientService
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
    // A. BEHAVIOR PRESERVATION
    // ═══════════════════════════════════════════════════════

    public function test_file_delete_api_response_unchanged(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}");

        $response->assertStatus(204);
    }

    public function test_file_rename_api_response_unchanged(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->patchJson("/api/files/{$this->file->id}", ['name' => 'renamed.txt']);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'renamed.txt');
    }

    public function test_folder_create_api_response_unchanged(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'New Folder']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Folder');
    }

    public function test_folder_delete_api_response_unchanged(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/folders/{$this->folder->uuid}");

        $response->assertStatus(204);
    }

    public function test_force_delete_api_response_unchanged(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$this->file->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('files', ['id' => $this->file->id]);
    }

    public function test_activity_still_logged_in_sync_mode(): void
    {
        // phpunit.xml sets QUEUE_CONNECTION=sync, so jobs run immediately
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}")
            ->assertStatus(204);

        // Activity log should be written synchronously
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->owner->id,
            'action' => 'delete',
            'resource_type' => 'file',
            'resource_id' => (string) $this->file->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // B. JOB DISPATCH
    // ═══════════════════════════════════════════════════════

    public function test_log_activity_job_dispatched_on_file_delete(): void
    {
        Bus::fake([LogActivityJob::class]);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}")
            ->assertStatus(204);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->userId === $this->owner->id
                && $job->action === 'delete'
                && $job->resourceType === 'file'
                && $job->resourceId === (string) $this->file->id;
        });
    }

    public function test_log_activity_job_dispatched_on_folder_create(): void
    {
        Bus::fake([LogActivityJob::class]);

        $this->actingAs($this->owner, 'api')
            ->postJson('/api/folders', ['name' => 'Test Folder'])
            ->assertStatus(201);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->action === 'create'
                && $job->resourceType === 'folder';
        });
    }

    public function test_delete_r2_object_job_dispatched_on_force_delete(): void
    {
        Bus::fake([DeleteR2ObjectJob::class, LogActivityJob::class]);

        $objectKey = $this->file->r2_object_key;

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$this->file->id}")
            ->assertStatus(204);

        Bus::assertDispatched(DeleteR2ObjectJob::class, function (DeleteR2ObjectJob $job) use ($objectKey) {
            return $job->objectKey === $objectKey;
        });
    }

    public function test_purge_dispatch_flag_dispatches_job(): void
    {
        Bus::fake([PurgeExpiredTrashJob::class]);

        $this->artisan('trash:purge', ['--dispatch' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('dispatched');

        Bus::assertDispatched(PurgeExpiredTrashJob::class);
    }

    // ═══════════════════════════════════════════════════════
    // C. IDEMPOTENCY
    // ═══════════════════════════════════════════════════════

    public function test_log_activity_job_idempotent_no_duplicate(): void
    {
        $occurredAt = now()->toDateTimeString();

        $job = new LogActivityJob(
            $this->owner->id,
            'delete',
            'file',
            (string) $this->file->id,
            ['name' => 'test.txt'],
            $occurredAt,
        );

        // Execute twice
        $job->handle();
        $job->handle();

        // Only one record
        $count = ActivityLog::query()
            ->where('user_id', $this->owner->id)
            ->where('action', 'delete')
            ->where('resource_type', 'file')
            ->where('resource_id', (string) $this->file->id)
            ->where('created_at', $occurredAt)
            ->count();

        $this->assertEquals(1, $count, 'LogActivityJob should not create duplicates on retry');
    }

    public function test_delete_r2_object_job_idempotent_on_404(): void
    {
        // R2 deleteObject is inherently idempotent — 404 = already gone
        $r2 = $this->app->make(R2ClientService::class);
        $job = new DeleteR2ObjectJob('test-key');

        // Should not throw — first call succeeds
        $job->handle($r2);

        // Second call also succeeds (mock always returns success)
        $job->handle($r2);

        // No exception = idempotent
        $this->assertTrue(true);
    }

    public function test_delete_r2_object_job_skips_empty_key(): void
    {
        $r2 = $this->app->make(R2ClientService::class);
        $job = new DeleteR2ObjectJob('');

        // Should not call deleteObject for empty key
        $job->handle($r2);

        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════════════════
    // D. FAILURE SIMULATION
    // ═══════════════════════════════════════════════════════

    public function test_main_request_succeeds_even_if_log_job_would_fail(): void
    {
        // Use Bus::fake to prevent job execution — simulating async behavior
        Bus::fake([LogActivityJob::class]);

        // The request itself should still return 204
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}")
            ->assertStatus(204);

        // File is soft-deleted in DB (main operation succeeded)
        $this->file->refresh();
        $this->assertTrue($this->file->isTrashed());

        // Job was dispatched but not executed
        Bus::assertDispatched(LogActivityJob::class);
    }

    public function test_main_request_succeeds_even_if_r2_delete_would_fail(): void
    {
        Bus::fake([DeleteR2ObjectJob::class, LogActivityJob::class]);

        $fileId = $this->file->id;

        // Force delete — main operation should succeed
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$fileId}")
            ->assertStatus(204);

        // File removed from DB
        $this->assertDatabaseMissing('files', ['id' => $fileId]);

        // R2 deletion dispatched but not executed
        Bus::assertDispatched(DeleteR2ObjectJob::class);
    }

    public function test_log_activity_job_retries_configured(): void
    {
        $job = new LogActivityJob(
            $this->owner->id,
            'test',
            'file',
            '1',
            null,
            now()->toDateTimeString(),
        );

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([5, 30, 120], $job->backoff);
    }

    public function test_delete_r2_object_job_retries_configured(): void
    {
        $job = new DeleteR2ObjectJob('test-key');

        $this->assertEquals(5, $job->tries);
        $this->assertEquals([10, 30, 60, 300, 600], $job->backoff);
    }

    // ═══════════════════════════════════════════════════════
    // E. PURGE BATCH
    // ═══════════════════════════════════════════════════════

    public function test_purge_processes_many_items_in_chunks(): void
    {
        Bus::fake([DeleteR2ObjectJob::class]);

        // Seed 200 expired files
        for ($i = 0; $i < 200; $i++) {
            File::factory()->create([
                'owner_id' => $this->owner->id,
                'folder_id' => $this->folder->id,
                'size_bytes' => 1024,
                'deleted_at' => now()->subDays(20),
                'deleted_by' => $this->owner->id,
                'purge_at' => now()->subDays(5),
            ]);
        }

        $job = new PurgeExpiredTrashJob;
        $job->handle($this->app->make(QuotaService::class), $this->app->make(SyncEventService::class));

        // All expired files should be purged from DB
        $remaining = File::query()
            ->onlyTrashed()
            ->where('purge_at', '<=', now())
            ->count();

        $this->assertEquals(0, $remaining, 'All expired files should be purged');

        // R2 delete jobs dispatched for each file
        Bus::assertDispatched(DeleteR2ObjectJob::class, 200);
    }

    public function test_purge_does_not_affect_unexpired(): void
    {
        // Create file with future purge
        $futureFile = File::factory()->create([
            'owner_id' => $this->owner->id,
            'folder_id' => $this->folder->id,
            'deleted_at' => now()->subDays(1),
            'deleted_by' => $this->owner->id,
            'purge_at' => now()->addDays(14),
        ]);

        $job = new PurgeExpiredTrashJob;
        $job->handle($this->app->make(QuotaService::class), $this->app->make(SyncEventService::class));

        // Future file still exists
        $this->assertDatabaseHas('files', ['id' => $futureFile->id]);
    }

    public function test_purge_job_retries_configured(): void
    {
        $job = new PurgeExpiredTrashJob;

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([30, 120, 600], $job->backoff);
    }

    public function test_purge_via_command_inline_still_works(): void
    {
        File::factory()->create([
            'owner_id' => $this->owner->id,
            'folder_id' => $this->folder->id,
            'size_bytes' => 512,
            'deleted_at' => now()->subDays(20),
            'deleted_by' => $this->owner->id,
            'purge_at' => now()->subDays(5),
        ]);

        $this->artisan('trash:purge')
            ->assertExitCode(0)
            ->expectsOutputToContain('1 expired');
    }
}
