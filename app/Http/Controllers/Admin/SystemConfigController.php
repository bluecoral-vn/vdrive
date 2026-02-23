<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemConfigRequest;
use App\Models\EmailLog;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class SystemConfigController extends Controller
{
    public function __construct(private SystemConfigService $configService) {}

    /**
     * List all system configurations.
     */
    public function index(): JsonResponse
    {
        if (! auth()->user()->hasPermission('system-config.view')) {
            abort(403);
        }

        return response()->json([
            'data' => $this->configService->getAll(),
        ]);
    }

    /**
     * Bulk-update system configurations.
     */
    public function update(UpdateSystemConfigRequest $request): JsonResponse
    {
        if (! auth()->user()->hasPermission('system-config.update')) {
            abort(403);
        }

        $this->configService->bulkSet($request->validated('configs'));

        return response()->json([
            'message' => 'Configuration updated.',
            'data' => $this->configService->getAll(),
        ]);
    }

    /**
     * Test SMTP connection by sending a test email.
     */
    public function testSmtp(Request $request): JsonResponse
    {
        if (! auth()->user()->hasPermission('system-config.update')) {
            abort(403);
        }

        $recipient = $request->validate([
            'recipient' => ['required', 'email'],
        ])['recipient'];

        // Apply runtime SMTP config
        $this->applySmtpConfig();

        $mailable = new \App\Mail\TestSmtpMail;
        $subject = $mailable->envelope()->subject;

        $emailLog = EmailLog::query()->create([
            'recipient' => $recipient,
            'subject' => $subject,
            'status' => 'queued',
            'resource_type' => 'smtp_test',
            'resource_id' => null,
            'share_id' => null,
            'metadata' => [
                'triggered_by' => auth()->id(),
            ],
        ]);

        try {
            $renderedBody = $mailable->render();

            Mail::to($recipient)->send($mailable);

            $emailLog->update([
                'status' => 'success',
                'body' => $renderedBody,
                'sent_at' => now(),
            ]);

            return response()->json([
                'message' => 'Test email sent successfully.',
                'recipient' => $recipient,
            ]);
        } catch (\Throwable $e) {
            $emailLog->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return response()->json([
                'message' => 'SMTP test failed.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Apply SMTP settings from SystemConfigService at runtime.
     */
    private function applySmtpConfig(): void
    {
        $mapping = [
            'smtp_host' => 'mail.mailers.smtp.host',
            'smtp_port' => 'mail.mailers.smtp.port',
            'smtp_username' => 'mail.mailers.smtp.username',
            'smtp_password' => 'mail.mailers.smtp.password',
            'smtp_encryption' => 'mail.mailers.smtp.scheme',
            'smtp_from_address' => 'mail.from.address',
            'smtp_from_name' => 'mail.from.name',
        ];

        foreach ($mapping as $configKey => $laravelKey) {
            $value = $this->configService->resolve($configKey);
            if ($value && $value !== 'null') {
                $setVal = $configKey === 'smtp_port' ? (int) $value : $value;
                Config::set($laravelKey, $setVal);
            }
        }

        // Force mailer to smtp
        $host = $this->configService->resolve('smtp_host');
        if ($host) {
            Config::set('mail.default', 'smtp');
        }
    }
}
