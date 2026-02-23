<?php

namespace Tests\Integration;

use App\Jobs\DeleteR2ObjectJob;
use App\Jobs\LogActivityJob;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Integration — Background Job Tests.
 */
class BackgroundJobIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Folder $folder;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mockR2();

        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->owner = User::factory()->create(['quota_used_bytes' => 10485760]);
        $this->owner->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
            'size_bytes' => 1024,
        ]);
    }

    private function mockR2(): void
    {
        $mockS3 = \Mockery::mock(\Aws\S3\S3Client::class);
        $mockS3->shouldReceive('createMultipartUpload')->andReturn(['UploadId' => 'mock'])->byDefault();
        $mockS3->shouldReceive('getCommand')->andReturn(\Mockery::mock(\Aws\CommandInterface::class))->byDefault();
        $mockRequest = \Mockery::mock(\Psr\Http\Message\RequestInterface::class);
        $mockRequest->shouldReceive('getUri')->andReturn(new \GuzzleHttp\Psr7\Uri('https://r2.example.com'))->byDefault();
        $mockS3->shouldReceive('createPresignedRequest')->andReturn($mockRequest)->byDefault();
        $mockS3->shouldReceive('completeMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('abortMultipartUpload')->andReturn([])->byDefault();
        $mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();

        $this->instance(\App\Services\R2ClientService::class, new class($mockS3) extends \App\Services\R2ClientService
        {
            public function __construct(private \Aws\S3\S3Client $mock) {}

            public function client(): \Aws\S3\S3Client
            {
                return $this->mock;
            }

            public function bucket(): string
            {
                return 'test-bucket';
            }
        });
    }

    // ── Job Dispatch After Commit ─────────────────────────

    public function test_log_activity_dispatched_on_file_delete(): void
    {
        Bus::fake([LogActivityJob::class]);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}")
            ->assertStatus(204);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->userId === $this->owner->id
                && $job->action === 'delete'
                && $job->resourceType === 'file';
        });
    }

    public function test_delete_r2_dispatched_on_force_delete(): void
    {
        Bus::fake([DeleteR2ObjectJob::class, LogActivityJob::class]);

        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/trash/files/{$this->file->id}")
            ->assertStatus(204);

        Bus::assertDispatched(DeleteR2ObjectJob::class, function (DeleteR2ObjectJob $job) {
            return $job->objectKey !== '';
        });
    }

    // ── Idempotent Job Execution ─────────────────────────

    public function test_log_activity_idempotent(): void
    {
        $ts = now()->toDateTimeString();

        $job = new LogActivityJob(
            $this->owner->id,
            'delete',
            'file',
            (string) $this->file->id,
            null,
            $ts,
        );

        // Run three times
        $job->handle();
        $job->handle();
        $job->handle();

        $count = ActivityLog::query()
            ->where('user_id', $this->owner->id)
            ->where('action', 'delete')
            ->where('resource_type', 'file')
            ->where('resource_id', (string) $this->file->id)
            ->where('created_at', $ts)
            ->count();

        $this->assertEquals(1, $count, 'Idempotent: 3 executions = 1 record');
    }

    // ── Retry Does Not Duplicate Delete ──────────────────

    public function test_r2_delete_retry_does_not_cause_error(): void
    {
        $r2 = $this->app->make(\App\Services\R2ClientService::class);
        $job = new DeleteR2ObjectJob('nonexistent-key');

        // Multiple runs should not throw (mock returns success)
        $job->handle($r2);
        $job->handle($r2);
        $job->handle($r2);

        $this->assertTrue(true, 'Retry of R2 delete should not throw');
    }

    // ── Sync Execution In Test ───────────────────────────

    public function test_jobs_execute_synchronously_in_test(): void
    {
        // QUEUE_CONNECTION=sync, so dispatched jobs run immediately
        $this->actingAs($this->owner, 'api')
            ->deleteJson("/api/files/{$this->file->id}")
            ->assertStatus(204);

        // Activity log should already exist (sync execution)
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->owner->id,
            'action' => 'delete',
            'resource_type' => 'file',
        ]);
    }
}
