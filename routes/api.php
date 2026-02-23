<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\BulkDeleteController;
use App\Http\Controllers\MoveController;
use App\Http\Controllers\QuotaController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/** @unauthenticated */
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware(['auth:api', 'ensure.active'])->group(function () {
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/**
 * Guest share access — public, no auth required.
 *
 * @unauthenticated
 */
Route::get('/share/{token}', [ShareController::class, 'showByToken'])
    ->where('token', '[A-Za-z0-9]{64}');

/** @unauthenticated */
Route::get('/share/{token}/download', [ShareController::class, 'downloadByToken'])
    ->where('token', '[A-Za-z0-9]{64}');

/** @unauthenticated */
Route::get('/share/{token}/folders/{folderUuid}', [ShareController::class, 'browseFolderByToken'])
    ->where(['token' => '[A-Za-z0-9]{64}', 'folderUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}']);

/** @unauthenticated */
Route::get('/share/{token}/files/{fileId}', [ShareController::class, 'fileByToken'])
    ->where(['token' => '[A-Za-z0-9]{64}', 'fileId' => '[0-9a-f\-]{36}']);

/** @unauthenticated */
Route::get('/branding', [Admin\BrandingController::class, 'publicShow']);

Route::middleware(['auth:api', 'ensure.active'])->group(function () {
    Route::apiResource('users', UserController::class);
    Route::patch('/users/{user}/disable', [UserController::class, 'disable']);
    Route::patch('/users/{user}/enable', [UserController::class, 'enable']);
    Route::patch('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

    $uuidRegex = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    Route::get('/folders', [FolderController::class, 'index']);
    Route::post('/folders', [FolderController::class, 'store']);
    Route::get('/folders/{folder}', [FolderController::class, 'show'])->where('folder', $uuidRegex);
    Route::get('/folders/{folder}/children', [FolderController::class, 'children'])->where('folder', $uuidRegex);
    Route::get('/folders/{folder}/files', [FolderController::class, 'files'])->where('folder', $uuidRegex);
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])->where('folder', $uuidRegex);
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->where('folder', $uuidRegex);

    Route::get('/files/{file}', [FileController::class, 'show'])->where('file', $uuidRegex);
    Route::patch('/files/{file}', [FileController::class, 'update'])->where('file', $uuidRegex);
    Route::delete('/files/{file}', [FileController::class, 'destroy'])->where('file', $uuidRegex);
    Route::get('/files/{file}/download', [FileController::class, 'download'])->where('file', $uuidRegex);
    Route::get('/files/{file}/stream', [FileController::class, 'stream'])->where('file', $uuidRegex);
    Route::get('/files/{file}/preview', [FileController::class, 'preview'])->where('file', $uuidRegex);
    Route::get('/files/{file}/content', [FileController::class, 'content'])->where('file', $uuidRegex);
    Route::get('/files/{file}/thumbnail', [FileController::class, 'thumbnail'])->where('file', $uuidRegex);

    Route::post('/share', [ShareController::class, 'store']);
    Route::get('/share/with-me', [ShareController::class, 'withMe']);
    Route::get('/share/by-me', [ShareController::class, 'byMe']);
    Route::patch('/share/{share}', [ShareController::class, 'update'])
        ->where('share', $uuidRegex);
    Route::delete('/share/{share}', [ShareController::class, 'revoke'])
        ->where('share', $uuidRegex);

    Route::get('/me/quota', [QuotaController::class, 'show']);

    Route::get('/activity', [ActivityLogController::class, 'index']);

    Route::get('/search', [SearchController::class, 'index']);

    Route::post('/move', MoveController::class);
    Route::post('/delete', BulkDeleteController::class);

    Route::prefix('sync')->group(function () {
        Route::get('/delta', [SyncController::class, 'delta']);
        Route::get('/status', [SyncController::class, 'status']);
    });

    Route::prefix('trash')->group(function () use ($uuidRegex) {
        Route::get('/', [TrashController::class, 'index']);
        Route::delete('/', [TrashController::class, 'empty']);
        Route::post('/files/{file}/restore', [TrashController::class, 'restoreFile'])->where('file', $uuidRegex);
        Route::post('/folders/{folder}/restore', [TrashController::class, 'restoreFolder'])->where('folder', $uuidRegex);
        Route::delete('/files/{file}', [TrashController::class, 'forceDeleteFile'])->where('file', $uuidRegex);
        Route::delete('/folders/{folder}', [TrashController::class, 'forceDeleteFolder'])->where('folder', $uuidRegex);
    });

    Route::prefix('upload')->group(function () {
        Route::post('/init', [UploadController::class, 'init']);
        Route::post('/presign-part', [UploadController::class, 'presignPart']);
        Route::post('/complete', [UploadController::class, 'complete']);
        Route::post('/abort', [UploadController::class, 'abort']);
    });

    Route::prefix('admin/system')->group(function () {
        Route::get('/config', [Admin\SystemConfigController::class, 'index']);
        Route::put('/config', [Admin\SystemConfigController::class, 'update']);
        Route::post('/smtp-test', [Admin\SystemConfigController::class, 'testSmtp']);
    });

    Route::prefix('admin')->group(function () use ($uuidRegex) {
        Route::get('/email-logs', [Admin\EmailLogController::class, 'index']);
        Route::get('/email-logs/{emailLog}', [Admin\EmailLogController::class, 'show'])->where('emailLog', $uuidRegex);
    });

    Route::prefix('admin/settings')->group(function () {
        Route::get('/branding', [Admin\BrandingController::class, 'show']);
        Route::post('/branding', [Admin\BrandingController::class, 'update']);
    });

    Route::prefix('admin/backups')->group(function () use ($uuidRegex) {
        Route::get('/', [Admin\BackupController::class, 'index']);
        Route::get('/config', [Admin\BackupController::class, 'config']);
        Route::put('/config', [Admin\BackupController::class, 'updateConfig']);
        Route::post('/trigger', [Admin\BackupController::class, 'trigger']);
        Route::get('/{backup}/download', [Admin\BackupController::class, 'download'])
            ->where('backup', $uuidRegex);
        Route::delete('/{backup}', [Admin\BackupController::class, 'destroy'])
            ->where('backup', $uuidRegex);
    });

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'store']);
    Route::delete('/favorites', [FavoriteController::class, 'destroy']);
    Route::post('/favorites/bulk', [FavoriteController::class, 'bulkStore']);
    Route::delete('/favorites/bulk', [FavoriteController::class, 'bulkDestroy']);

    // Tags
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::patch('/tags/{tag}', [TagController::class, 'update'])->where('tag', $uuidRegex);
    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->where('tag', $uuidRegex);
    Route::post('/tags/assign', [TagController::class, 'assign']);
    Route::post('/tags/unassign', [TagController::class, 'unassign']);
    Route::get('/tags/{tag}/items', [TagController::class, 'items'])->where('tag', $uuidRegex);
});
