<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetJob;
use App\Mail\PasswordResetMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'john@example.com',
            'name' => 'John Doe',
            'password' => 'old-password-123',
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // FORGOT PASSWORD
    // ═══════════════════════════════════════════════════════

    public function test_forgot_password_dispatches_job_for_existing_user(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'If an account with that email exists, a reset link has been sent.']);

        Queue::assertPushed(SendPasswordResetJob::class, function ($job) {
            return $job->email === 'john@example.com'
                && $job->recipientName === 'John Doe';
        });

        // Token should be stored in DB
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_forgot_password_returns_same_message_for_nonexistent_email(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'If an account with that email exists, a reset link has been sent.']);

        Queue::assertNotPushed(SendPasswordResetJob::class);
    }

    public function test_forgot_password_returns_same_message_for_disabled_user(): void
    {
        Queue::fake();

        $this->user->update([
            'status' => 'disabled',
            'disabled_at' => now(),
            'disabled_reason' => 'Test disabled',
        ]);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'john@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'If an account with that email exists, a reset link has been sent.']);

        Queue::assertNotPushed(SendPasswordResetJob::class);
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_requires_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ═══════════════════════════════════════════════════════
    // RESET PASSWORD
    // ═══════════════════════════════════════════════════════

    public function test_reset_password_with_valid_token(): void
    {
        $service = app(\App\Services\PasswordResetService::class);
        $rawToken = $service->createToken('john@example.com');

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => $rawToken,
            'password' => 'new-secure-pass-123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Password has been reset successfully.']);

        // Verify new password works for login
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'john@example.com',
            'password' => 'new-secure-pass-123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure(['access_token']);

        // Verify old password no longer works
        $oldLoginResponse = $this->postJson('/api/auth/login', [
            'email' => 'john@example.com',
            'password' => 'old-password-123',
        ]);

        $oldLoginResponse->assertStatus(401);
    }

    public function test_reset_password_with_invalid_token(): void
    {
        // Create a real token but use a different one
        $service = app(\App\Services\PasswordResetService::class);
        $service->createToken('john@example.com');

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => 'totally-wrong-token',
            'password' => 'new-secure-pass-123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired reset token.']);
    }

    public function test_reset_password_with_expired_token(): void
    {
        $service = app(\App\Services\PasswordResetService::class);
        $rawToken = $service->createToken('john@example.com');

        // Manually backdate the token to 61 minutes ago
        DB::table('password_reset_tokens')
            ->where('email', 'john@example.com')
            ->update(['created_at' => now()->subMinutes(61)]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => $rawToken,
            'password' => 'new-secure-pass-123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired reset token.']);
    }

    public function test_reset_password_invalidates_token_after_use(): void
    {
        $service = app(\App\Services\PasswordResetService::class);
        $rawToken = $service->createToken('john@example.com');

        // First reset — should succeed
        $first = $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => $rawToken,
            'password' => 'new-secure-pass-123',
        ]);

        $first->assertStatus(200);

        // Second reset with same token — should fail
        $second = $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => $rawToken,
            'password' => 'another-pass-456',
        ]);

        $second->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired reset token.']);
    }

    public function test_reset_password_validates_input(): void
    {
        $response = $this->postJson('/api/auth/reset-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'token', 'password']);
    }

    public function test_reset_password_requires_minimum_8_characters(): void
    {
        $service = app(\App\Services\PasswordResetService::class);
        $rawToken = $service->createToken('john@example.com');

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => $rawToken,
            'password' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_increments_token_version(): void
    {
        $originalVersion = $this->user->token_version;

        $service = app(\App\Services\PasswordResetService::class);
        $rawToken = $service->createToken('john@example.com');

        $this->postJson('/api/auth/reset-password', [
            'email' => 'john@example.com',
            'token' => $rawToken,
            'password' => 'new-secure-pass-123',
        ])->assertStatus(200);

        $this->user->refresh();
        $this->assertEquals($originalVersion + 1, $this->user->token_version);
    }

    // ═══════════════════════════════════════════════════════
    // JOB EXECUTION — EMAIL LOG
    // ═══════════════════════════════════════════════════════

    public function test_job_creates_email_log_and_sends_mail(): void
    {
        Mail::fake();

        $job = new SendPasswordResetJob(
            email: 'john@example.com',
            recipientName: 'John Doe',
            resetUrl: 'http://localhost/reset-password?token=abc123&email=john@example.com',
        );

        $job->handle(app(\App\Services\SystemConfigService::class));

        Mail::assertSent(PasswordResetMail::class, function ($mail) {
            return $mail->hasTo('john@example.com');
        });

        $this->assertDatabaseHas('email_logs', [
            'recipient' => 'john@example.com',
            'status' => 'success',
            'resource_type' => 'password_reset',
        ]);
    }

    public function test_password_reset_email_contains_branding(): void
    {
        /** @var \App\Services\SystemConfigService $configService */
        $configService = app(\App\Services\SystemConfigService::class);
        $configService->set('branding.app_name', 'TestBrand');
        $configService->set('branding.copyright_text', '© 2026 TestBrand Inc.');
        $configService->set('branding.tag_line', 'Store smarter');

        $mailable = new PasswordResetMail(
            resetUrl: 'http://localhost/reset?token=abc',
            recipientName: 'John Doe',
        );

        $html = $mailable->render();

        $this->assertStringContainsString('TestBrand', $html);
        $this->assertStringContainsString('© 2026 TestBrand Inc.', $html);
        $this->assertStringContainsString('Store smarter', $html);
    }

    public function test_job_is_idempotent(): void
    {
        Mail::fake();

        // Create an existing success log within the 60-minute window
        EmailLog::query()->create([
            'recipient' => 'john@example.com',
            'subject' => 'Password Reset Request',
            'status' => 'success',
            'resource_type' => 'password_reset',
            'resource_id' => null,
        ]);

        $job = new SendPasswordResetJob(
            email: 'john@example.com',
            recipientName: 'John Doe',
            resetUrl: 'http://localhost/reset-password?token=abc123&email=john@example.com',
        );

        $job->handle(app(\App\Services\SystemConfigService::class));

        // Should NOT send again
        Mail::assertNothingSent();
    }
}
