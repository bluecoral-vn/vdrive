<?php

namespace Tests\Feature;

use App\Jobs\SendShareNotificationJob;
use App\Mail\ShareNotificationMail;
use App\Mail\TestSmtpMail;
use App\Models\EmailLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Role;
use App\Models\Share;
use App\Models\User;
use App\Services\SystemConfigService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $recipient;

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

        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($userRole);

        $this->recipient = User::factory()->create();
        $this->recipient->roles()->attach($userRole);

        $this->folder = Folder::factory()->create(['owner_id' => $this->owner->id]);
        $this->file = File::factory()->create([
            'folder_id' => $this->folder->id,
            'owner_id' => $this->owner->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // SHARE WITH NOTIFICATION — FILE
    // ═══════════════════════════════════════════════════════

    public function test_share_file_with_notification_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
                'send_notification' => true,
                'notes' => 'Check this out!',
            ]);

        $response->assertStatus(201);
        $this->assertEquals('Check this out!', $response->json('data.notes'));

        Queue::assertPushed(SendShareNotificationJob::class, function ($job) {
            return $job->recipientId === $this->recipient->id
                && $job->resourceType === 'file'
                && $job->notes === 'Check this out!';
        });
    }

    public function test_share_file_without_notification_does_not_dispatch_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
                'send_notification' => false,
            ]);

        $response->assertStatus(201);

        Queue::assertNotPushed(SendShareNotificationJob::class);
    }

    public function test_share_without_send_notification_does_not_dispatch_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
            ]);

        $response->assertStatus(201);

        Queue::assertNotPushed(SendShareNotificationJob::class);
    }

    // ═══════════════════════════════════════════════════════
    // SHARE WITH NOTIFICATION — FOLDER
    // ═══════════════════════════════════════════════════════

    public function test_share_folder_with_notification_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'folder_id' => $this->folder->uuid,
                'shared_with' => $this->recipient->id,
                'permission' => 'edit',
                'send_notification' => true,
            ]);

        $response->assertStatus(201);

        Queue::assertPushed(SendShareNotificationJob::class, function ($job) {
            return $job->recipientId === $this->recipient->id
                && $job->resourceType === 'folder';
        });
    }

    // ═══════════════════════════════════════════════════════
    // GUEST LINK — NO NOTIFICATION
    // ═══════════════════════════════════════════════════════

    public function test_guest_link_with_send_notification_does_not_dispatch_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'permission' => 'view',
                'send_notification' => true,
            ]);

        $response->assertStatus(201);

        // Guest links have no recipient — no notification
        Queue::assertNotPushed(SendShareNotificationJob::class);
    }

    // ═══════════════════════════════════════════════════════
    // NOTES FIELD
    // ═══════════════════════════════════════════════════════

    public function test_notes_field_persisted_on_share(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
                'notes' => 'Here is the document you requested.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.notes', 'Here is the document you requested.');

        $share = Share::query()->find($response->json('data.id'));
        $this->assertEquals('Here is the document you requested.', $share->notes);
    }

    public function test_notes_validation_max_length(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/share', [
                'file_id' => $this->file->id,
                'shared_with' => $this->recipient->id,
                'permission' => 'view',
                'notes' => str_repeat('a', 1001),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    // ═══════════════════════════════════════════════════════
    // JOB EXECUTION — EMAIL LOG
    // ═══════════════════════════════════════════════════════

    public function test_job_creates_email_log_and_sends_mail(): void
    {
        Mail::fake();

        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $job = new SendShareNotificationJob(
            shareId: $share->id,
            recipientId: $this->recipient->id,
            resourceName: $this->file->name,
            resourceType: 'file',
            notes: 'Test notes',
        );

        $job->handle(app(\App\Services\SystemConfigService::class));

        Mail::assertSent(ShareNotificationMail::class, function ($mail) {
            return $mail->hasTo($this->recipient->email);
        });

        $this->assertDatabaseHas('email_logs', [
            'share_id' => $share->id,
            'recipient' => $this->recipient->email,
            'status' => 'success',
            'resource_type' => 'file',
        ]);
    }

    public function test_share_notification_email_contains_branding(): void
    {
        /** @var SystemConfigService $configService */
        $configService = app(SystemConfigService::class);
        $configService->set('branding.app_name', 'TestBrand');
        $configService->set('branding.copyright_text', '© 2026 TestBrand Inc.');
        $configService->set('branding.tag_line', 'Store smarter');

        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        $mailable = new ShareNotificationMail(
            share: $share,
            sharer: $this->owner,
            resourceName: $this->file->name,
            resourceType: 'file',
        );

        $html = $mailable->render();

        $this->assertStringContainsString('TestBrand', $html);
        $this->assertStringContainsString('© 2026 TestBrand Inc.', $html);
        $this->assertStringContainsString('Store smarter', $html);
    }

    public function test_job_is_idempotent(): void
    {
        Mail::fake();

        $share = Share::query()->create([
            'file_id' => $this->file->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->recipient->id,
            'permission' => 'view',
        ]);

        // Create an existing success log
        EmailLog::query()->create([
            'share_id' => $share->id,
            'recipient' => $this->recipient->email,
            'subject' => 'test',
            'status' => 'success',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);

        $job = new SendShareNotificationJob(
            shareId: $share->id,
            recipientId: $this->recipient->id,
            resourceName: $this->file->name,
            resourceType: 'file',
        );

        $job->handle(app(\App\Services\SystemConfigService::class));

        // Should NOT send again
        Mail::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════
    // ADMIN EMAIL LOG API
    // ═══════════════════════════════════════════════════════

    public function test_admin_can_list_email_logs(): void
    {
        EmailLog::query()->create([
            'recipient' => 'user@example.com',
            'subject' => 'Test Subject',
            'status' => 'success',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/email-logs');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_filter_email_logs_by_status(): void
    {
        EmailLog::query()->create([
            'recipient' => 'user1@example.com',
            'subject' => 'Success',
            'status' => 'success',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);

        EmailLog::query()->create([
            'recipient' => 'user2@example.com',
            'subject' => 'Failed',
            'status' => 'failed',
            'resource_type' => 'folder',
            'resource_id' => $this->folder->uuid,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/email-logs?status=failed');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_admin_can_view_single_email_log(): void
    {
        $log = EmailLog::query()->create([
            'recipient' => 'user@example.com',
            'subject' => 'Test Subject',
            'status' => 'success',
            'resource_type' => 'file',
            'resource_id' => $this->file->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/email-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.recipient', 'user@example.com');
    }

    public function test_regular_user_cannot_access_email_logs(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->getJson('/api/admin/email-logs');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_email_logs(): void
    {
        $response = $this->getJson('/api/admin/email-logs');

        $response->assertUnauthorized();
    }

    // ═══════════════════════════════════════════════════════
    // SMTP TEST ENDPOINT
    // ═══════════════════════════════════════════════════════

    public function test_admin_can_test_smtp(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/system/smtp-test', [
                'recipient' => 'test@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Test email sent successfully.')
            ->assertJsonPath('recipient', 'test@example.com');

        Mail::assertSent(TestSmtpMail::class);

        // Verify email log was created
        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'test@example.com',
            'status' => 'success',
            'resource_type' => 'smtp_test',
            'resource_id' => null,
            'share_id' => null,
        ]);

        $log = EmailLog::query()->where('resource_type', 'smtp_test')->first();
        $this->assertEquals($this->admin->id, $log->metadata['triggered_by']);
    }

    public function test_regular_user_cannot_test_smtp(): void
    {
        $response = $this->actingAs($this->owner, 'api')
            ->postJson('/api/admin/system/smtp-test', [
                'recipient' => 'test@example.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_smtp_test_requires_valid_email(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/system/smtp-test', [
                'recipient' => 'not-an-email',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient']);
    }

    // ═══════════════════════════════════════════════════════
    // SMTP SYSTEM CONFIG KEYS
    // ═══════════════════════════════════════════════════════

    public function test_smtp_config_keys_visible_in_system_config(): void
    {
        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/system/config');

        $response->assertStatus(200);

        $keys = collect($response->json('data'))->pluck('key')->toArray();
        $this->assertContains('smtp_host', $keys);
        $this->assertContains('smtp_port', $keys);
        $this->assertContains('smtp_password', $keys);
        $this->assertContains('smtp_from_address', $keys);
    }

    public function test_smtp_password_is_masked(): void
    {
        $this->actingAs($this->admin, 'api')
            ->putJson('/api/admin/system/config', [
                'configs' => [
                    ['key' => 'smtp_password', 'value' => 'super-secret-pass'],
                ],
            ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/system/config');

        $smtpPassword = collect($response->json('data'))->firstWhere('key', 'smtp_password');
        $this->assertEquals('••••••••', $smtpPassword['value']);
        $this->assertTrue($smtpPassword['is_secret']);
    }
}
