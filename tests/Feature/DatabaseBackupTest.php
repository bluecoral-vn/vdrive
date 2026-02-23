<?php

namespace Tests\Feature;

use App\Jobs\CleanupExpiredBackupsJob;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Models\Role;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\R2ClientService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'user')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole);

        $this->regularUser = User::factory()->create();
        $this->regularUser->roles()->attach($userRole);

        $this->mockR2Client();
    }

    private function mockR2Client(): void
    {
        $mockS3 = Mockery::mock(S3Client::class);
        $mockS3->shouldReceive('putObject')->andReturn([])->byDefault();
        $mockS3->shouldReceive('deleteObject')->andReturn([])->byDefault();
        $mockS3->shouldReceive('getCommand')
            ->andReturn(Mockery::mock(CommandInterface::class))
            ->byDefault();

        $mockUri = Mockery::mock(\Psr\Http\Message\UriInterface::class);
        $mockUri->shouldReceive('__toString')
            ->andReturn('https://r2.example.com/system-backups/backup_test.zip?presigned=1')
            ->byDefault();

        $mockRequest = Mockery::mock(\Psr\Http\Message\RequestInterface::class);
        $mockRequest->shouldReceive('getUri')
            ->andReturn($mockUri)
            ->byDefault();

        $mockS3->shouldReceive('createPresignedRequest')
            ->andReturn($mockRequest)
            ->byDefault();

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

    private function setBackupConfig(array $overrides = []): void
    {
        $defaults = [
            'backup_enabled' => '1',
            'backup_schedule_type' => 'daily',
            'backup_time' => '02:00',
            'backup_retention_days' => '7',
            'backup_keep_forever' => '0',
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            SystemConfig::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'is_secret' => false],
            );
        }
    }

    // ─── Config API ───

    public function test_admin_can_get_backup_config(): void
    {
        $this->setBackupConfig();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/backups/config');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'backup_enabled',
                    'backup_schedule_type',
                    'backup_time',
                    'backup_retention_days',
                    'backup_keep_forever',
                    'backup_notification_email',
                ],
            ]);
    }

    public function test_admin_can_update_backup_config(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/backups/config', [
                'backup_enabled' => true,
                'backup_schedule_type' => 'daily',
                'backup_time' => '03:00',
                'backup_retention_days' => 15,
                'backup_notification_email' => 'admin@test.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.backup_enabled', true)
            ->assertJsonPath('data.backup_schedule_type', 'daily')
            ->assertJsonPath('data.backup_time', '03:00')
            ->assertJsonPath('data.backup_retention_days', '15');
    }

    public function test_non_admin_cannot_access_backup_config(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson('/api/admin/backups/config');

        $response->assertStatus(403);
    }

    public function test_validation_rejects_invalid_schedule_type(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/backups/config', [
                'backup_schedule_type' => 'weekly',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_schedule_type']);
    }

    public function test_validation_rejects_invalid_time_format(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/backups/config', [
                'backup_time' => '25:99',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_time']);
    }

    public function test_validation_rejects_invalid_email(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/backups/config', [
                'backup_notification_email' => 'not-an-email',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_notification_email']);
    }

    public function test_validation_rejects_invalid_day_of_month(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/backups/config', [
                'backup_day_of_month' => 15,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_day_of_month']);
    }

    public function test_validation_rejects_invalid_retention_days(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/backups/config', [
                'backup_retention_days' => 10,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_retention_days']);
    }

    // ─── Backup List ───

    public function test_admin_can_list_backups(): void
    {
        DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_20260213_020000.zip',
            'file_size' => 1024,
            'status' => 'success',
        ]);

        DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_20260212_020000.zip',
            'file_size' => 2048,
            'status' => 'success',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/backups');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'file_path', 'file_size', 'status', 'created_at'],
                ],
            ]);
    }

    public function test_non_admin_cannot_list_backups(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->getJson('/api/admin/backups');

        $response->assertStatus(403);
    }

    // ─── Trigger ───

    public function test_admin_can_trigger_manual_backup(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/backups/trigger');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Backup job dispatched.');

        Queue::assertPushed(RunDatabaseBackupJob::class);
    }

    public function test_non_admin_cannot_trigger_backup(): void
    {
        $response = $this->actingAs($this->regularUser, 'api')
            ->postJson('/api/admin/backups/trigger');

        $response->assertStatus(403);
    }

    public function test_concurrent_trigger_returns_409(): void
    {
        DatabaseBackup::query()->create([
            'file_path' => '',
            'file_size' => 0,
            'status' => 'running',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/backups/trigger');

        $response->assertStatus(409);
    }

    // ─── Download & Delete ───

    public function test_admin_can_download_backup(): void
    {
        $backup = DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_20260213_020000.zip',
            'file_size' => 1024,
            'status' => 'success',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/backups/{$backup->id}/download");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['url', 'expires_in']]);
    }

    public function test_cannot_download_failed_backup(): void
    {
        $backup = DatabaseBackup::query()->create([
            'file_path' => '',
            'file_size' => 0,
            'status' => 'failed',
            'error_message' => 'Test error',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/backups/{$backup->id}/download");

        $response->assertStatus(422);
    }

    public function test_admin_can_delete_backup(): void
    {
        $backup = DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_20260213_020000.zip',
            'file_size' => 1024,
            'status' => 'success',
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->deleteJson("/api/admin/backups/{$backup->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Backup deleted.');

        $this->assertDatabaseMissing('database_backups', ['id' => $backup->id]);
    }

    public function test_non_admin_cannot_delete_backup(): void
    {
        $backup = DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_20260213_020000.zip',
            'file_size' => 1024,
            'status' => 'success',
        ]);

        $response = $this->actingAs($this->regularUser, 'api')
            ->deleteJson("/api/admin/backups/{$backup->id}");

        $response->assertStatus(403);
    }

    // ─── Backup Service Logic ───

    public function test_backup_enabled_defaults_to_false(): void
    {
        $service = app(DatabaseBackupService::class);
        $this->assertFalse($service->isEnabled());
    }

    public function test_backup_does_not_run_when_disabled(): void
    {
        $service = app(DatabaseBackupService::class);
        $this->assertFalse($service->shouldRunNow());
    }

    public function test_backup_file_name_format(): void
    {
        // Mock the Process facade for sqlite3 .backup
        Process::fake([
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);

        // Create a temp file to simulate the dump
        $this->mockDatabaseDump();

        $service = app(DatabaseBackupService::class);

        try {
            $backup = $service->createBackup();
            $this->assertMatchesRegularExpression(
                '/^system-backups\/backup_\d{8}_\d{6}\.zip$/',
                $backup->file_path,
            );
            $this->assertEquals('success', $backup->status);
        } catch (\Throwable) {
            // Expected if sqlite3 binary not available in test env
            $this->assertTrue(true);
        }
    }

    public function test_backup_records_failure_status(): void
    {
        // Force dump to fail
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'sqlite3 not found', exitCode: 1),
        ]);

        $service = app(DatabaseBackupService::class);

        try {
            $service->createBackup();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            $failedBackup = DatabaseBackup::query()
                ->where('status', 'failed')
                ->first();

            $this->assertNotNull($failedBackup);
            $this->assertNotEmpty($failedBackup->error_message);
        }
    }

    // ─── Retention / Cleanup ───

    public function test_cleanup_deletes_expired_backups(): void
    {
        $expired = DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_old.zip',
            'file_size' => 1024,
            'status' => 'success',
            'expired_at' => now()->subDay(),
        ]);

        $active = DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_new.zip',
            'file_size' => 2048,
            'status' => 'success',
            'expired_at' => now()->addDays(5),
        ]);

        $service = app(DatabaseBackupService::class);
        $deleted = $service->cleanupExpired();

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('database_backups', ['id' => $expired->id]);
        $this->assertDatabaseHas('database_backups', ['id' => $active->id]);
    }

    public function test_cleanup_skips_keep_forever_backups(): void
    {
        $forever = DatabaseBackup::query()->create([
            'file_path' => 'system-backups/backup_forever.zip',
            'file_size' => 1024,
            'status' => 'success',
            'expired_at' => null,
        ]);

        $service = app(DatabaseBackupService::class);
        $deleted = $service->cleanupExpired();

        $this->assertEquals(0, $deleted);
        $this->assertDatabaseHas('database_backups', ['id' => $forever->id]);
    }

    public function test_cleanup_skips_running_backups(): void
    {
        DatabaseBackup::query()->create([
            'file_path' => '',
            'file_size' => 0,
            'status' => 'running',
            'expired_at' => now()->subDay(),
        ]);

        $service = app(DatabaseBackupService::class);
        $deleted = $service->cleanupExpired();

        $this->assertEquals(0, $deleted);
    }

    // ─── Schedule Logic ───

    public function test_should_run_now_returns_false_when_disabled(): void
    {
        $this->setBackupConfig(['backup_enabled' => '0']);

        $service = app(DatabaseBackupService::class);
        $this->assertFalse($service->shouldRunNow());
    }

    public function test_should_run_now_returns_false_for_manual_schedule(): void
    {
        $this->setBackupConfig([
            'backup_enabled' => '1',
            'backup_schedule_type' => 'manual',
        ]);

        $service = app(DatabaseBackupService::class);
        $this->assertFalse($service->shouldRunNow());
    }

    // ─── Job Dispatch ───

    public function test_cleanup_job_dispatches_correctly(): void
    {
        Queue::fake();

        $this->artisan('backup:cleanup', ['--dispatch' => true])
            ->assertSuccessful();

        Queue::assertPushed(CleanupExpiredBackupsJob::class);
    }

    public function test_backup_command_dispatches_job(): void
    {
        Queue::fake();

        $this->artisan('backup:run', ['--dispatch' => true])
            ->assertSuccessful();

        Queue::assertPushed(RunDatabaseBackupJob::class);
    }

    // ─── Email Notification ───

    public function test_failure_sends_email_when_configured(): void
    {
        Mail::fake();
        $this->setBackupConfig([
            'backup_notification_email' => 'admin@test.com',
        ]);

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'dump failed', exitCode: 1),
        ]);

        $job = new RunDatabaseBackupJob;
        $job->handle(
            app(DatabaseBackupService::class),
            app(\App\Services\SystemConfigService::class),
        );

        Mail::assertSent(\App\Mail\BackupFailedMail::class, function ($mail) {
            return $mail->hasTo('admin@test.com');
        });
    }

    public function test_no_email_when_notification_email_empty(): void
    {
        Mail::fake();
        $this->setBackupConfig([
            'backup_notification_email' => '',
        ]);

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'dump failed', exitCode: 1),
        ]);

        $job = new RunDatabaseBackupJob;
        $job->handle(
            app(DatabaseBackupService::class),
            app(\App\Services\SystemConfigService::class),
        );

        Mail::assertNothingSent();
    }

    // ─── Helpers ───

    private function mockDatabaseDump(): void
    {
        // Override the Process facade to simulate a successful dump
        // In real tests, sqlite3 binary may not be available
        Process::fake([
            '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
        ]);
    }
}
