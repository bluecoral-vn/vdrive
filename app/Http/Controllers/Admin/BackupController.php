<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBackupConfigRequest;
use App\Http\Resources\DatabaseBackupResource;
use App\Jobs\RunDatabaseBackupJob;
use App\Models\DatabaseBackup;
use App\Services\DatabaseBackupService;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;

class BackupController extends Controller
{
    public function __construct(
        private DatabaseBackupService $backupService,
        private SystemConfigService $configService,
    ) {}

    /**
     * List all database backups (paginated).
     */
    public function index(): JsonResponse
    {
        if (! auth()->user()->hasPermission('backups.manage')) {
            abort(403);
        }

        $backups = DatabaseBackup::query()
            ->orderByDesc('created_at')
            ->cursorPaginate(20);

        return DatabaseBackupResource::collection($backups)
            ->response();
    }

    /**
     * Get current backup configuration.
     */
    public function config(): JsonResponse
    {
        if (! auth()->user()->hasPermission('backups.manage')) {
            abort(403);
        }

        return response()->json([
            'data' => $this->backupService->getConfig(),
        ]);
    }

    /**
     * Update backup configuration.
     */
    public function updateConfig(UpdateBackupConfigRequest $request): JsonResponse
    {
        if (! auth()->user()->hasPermission('backups.manage')) {
            abort(403);
        }

        $validated = $request->validated();

        foreach ($validated as $key => $value) {
            $storeValue = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');

            // Handle nullable fields: empty string = null
            if ($value === null || $value === '') {
                $this->configService->set($key, null);
            } else {
                $this->configService->set($key, $storeValue);
            }
        }

        return response()->json([
            'message' => 'Backup configuration updated.',
            'data' => $this->backupService->getConfig(),
        ]);
    }

    /**
     * Trigger a manual backup.
     */
    public function trigger(): JsonResponse
    {
        if (! auth()->user()->hasPermission('backups.manage')) {
            abort(403);
        }

        // Check if a backup is already running
        $running = DatabaseBackup::query()
            ->where('status', 'running')
            ->exists();

        if ($running) {
            return response()->json([
                'message' => 'A backup is already running.',
            ], 409);
        }

        RunDatabaseBackupJob::dispatch();

        return response()->json([
            'message' => 'Backup job dispatched.',
        ]);
    }

    /**
     * Generate a presigned download URL for a backup.
     */
    public function download(DatabaseBackup $backup): JsonResponse
    {
        if (! auth()->user()->hasPermission('backups.manage')) {
            abort(403);
        }

        if ($backup->status !== 'success') {
            return response()->json([
                'message' => 'Backup is not available for download.',
            ], 422);
        }

        $url = $this->backupService->getDownloadUrl($backup);

        return response()->json([
            'data' => [
                'url' => $url,
                'expires_in' => 600,
            ],
        ]);
    }

    /**
     * Delete a specific backup.
     */
    public function destroy(DatabaseBackup $backup): JsonResponse
    {
        if (! auth()->user()->hasPermission('backups.manage')) {
            abort(403);
        }

        $this->backupService->deleteBackup($backup);

        return response()->json([
            'message' => 'Backup deleted.',
        ]);
    }
}
