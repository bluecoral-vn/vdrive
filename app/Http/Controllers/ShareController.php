<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShareRequest;
use App\Http\Requests\UpdateShareRequest;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Http\Resources\ShareResource;
use App\Jobs\LogActivityJob;
use App\Jobs\SendShareNotificationJob;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\Share;
use App\Services\FileAccessService;
use App\Services\ShareService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    public function __construct(
        private ShareService $shareService,
        private FileAccessService $fileAccessService,
    ) {}

    /**
     * Create a share (user-to-user or guest link) for a file or folder.
     */
    public function store(StoreShareRequest $request): JsonResponse
    {
        $user = $request->user();
        $notes = $request->validated('notes');
        $sendNotification = (bool) $request->validated('send_notification', false);

        $expiresAt = $request->validated('expires_at')
            ? Carbon::parse($request->validated('expires_at'))
            : null;

        if ($request->filled('folder_id')) {
            $folder = Folder::query()->where('uuid', $request->validated('folder_id'))->firstOrFail();

            if ($folder->owner_id !== $user->id) {
                abort(403);
            }

            $result = $this->shareService->createFolderShare(
                $user,
                $folder,
                $request->validated('shared_with'),
                $request->validated('permission'),
                $expiresAt,
                $notes,
            );

            LogActivityJob::dispatch(
                $user->id,
                'share',
                'folder',
                (string) $folder->uuid,
                [
                    'share_id' => $result['share']->id,
                    'shared_with' => $request->validated('shared_with'),
                    'permission' => $request->validated('permission'),
                    'is_guest_link' => $request->validated('shared_with') === null,
                ],
                now()->toDateTimeString(),
            );

            // Dispatch email notification for user-to-user shares
            if ($sendNotification && $request->filled('shared_with')) {
                SendShareNotificationJob::dispatch(
                    $result['share']->id,
                    $request->validated('shared_with'),
                    $folder->name,
                    'folder',
                    $notes,
                );
            }
        } else {
            $file = File::query()->findOrFail($request->validated('file_id'));

            if ($file->owner_id !== $user->id) {
                abort(403);
            }

            $result = $this->shareService->createShare(
                $user,
                $file,
                $request->validated('shared_with'),
                $request->validated('permission'),
                $expiresAt,
                $notes,
            );

            LogActivityJob::dispatch(
                $user->id,
                'share',
                'file',
                (string) $file->id,
                [
                    'share_id' => $result['share']->id,
                    'shared_with' => $request->validated('shared_with'),
                    'permission' => $request->validated('permission'),
                    'is_guest_link' => $request->validated('shared_with') === null,
                ],
                now()->toDateTimeString(),
            );

            // Dispatch email notification for user-to-user shares
            if ($sendNotification && $request->filled('shared_with')) {
                SendShareNotificationJob::dispatch(
                    $result['share']->id,
                    $request->validated('shared_with'),
                    $file->name,
                    'file',
                    $notes,
                );
            }
        }

        $result['share']->load(['file', 'folder', 'sharedBy', 'sharedWith']);

        $resource = new ShareResource($result['share']);
        $resource->rawToken = $result['token'];

        return $resource->response()->setStatusCode(201);
    }

    /**
     * Access a shared resource via guest token (public, no auth).
     *
     * For file shares: returns file metadata + presigned preview/download URLs.
     * For folder shares: returns folder metadata + children listing.
     *
     * @unauthenticated
     */
    public function showByToken(string $token): JsonResponse
    {
        $share = $this->shareService->findByToken($token);

        if (! $share) {
            abort(404);
        }

        if ($share->isExpired()) {
            return response()->json(['message' => 'Share link has expired.'], 410);
        }

        $resourceType = $share->isFileShare() ? 'file' : 'folder';
        $resourceId = $share->isFileShare() ? $share->file_id : $share->folder_id;

        // Log first view only — one entry per share
        ActivityLog::query()->firstOrCreate(
            [
                'action' => 'guest_view',
                'resource_type' => 'share',
                'resource_id' => (string) $share->id,
            ],
            [
                'user_id' => null,
                'metadata' => [
                    'shared_resource_type' => $resourceType,
                    'shared_resource_id' => $resourceId,
                ],
                'created_at' => now(),
            ],
        );

        $data = ['permission' => $share->permission];

        if ($share->isFileShare()) {
            $file = $share->file;
            $data['file'] = new FileResource($file);

            // Always include preview + download URLs (view includes download)
            $data['preview_url'] = $this->fileAccessService->generatePreviewUrl($file);
            $data['download_url'] = $this->fileAccessService->generateDownloadUrl($file);
        } else {
            $folder = $share->folder;
            $data['folder'] = new FolderResource($folder);

            // Include folder children (sub-folders + files)
            $data['children'] = FolderResource::collection(
                $folder->children()->whereNull('deleted_at')->orderBy('name')->get()
            );
            $data['files'] = FileResource::collection(
                $folder->files()->whereNull('deleted_at')->orderBy('name')->get()
            );
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Download a file via guest share token.
     *
     * Returns a presigned download URL. View permission includes download.
     *
     * @unauthenticated
     */
    public function downloadByToken(string $token): JsonResponse
    {
        $share = $this->shareService->findByToken($token);

        if (! $share || ! $share->isFileShare()) {
            abort(404);
        }

        if ($share->isExpired()) {
            return response()->json(['message' => 'Share link has expired.'], 410);
        }

        $file = $share->file;

        // Log first guest download only — one entry per share
        ActivityLog::query()->firstOrCreate(
            [
                'action' => 'guest_download',
                'resource_type' => 'share',
                'resource_id' => (string) $share->id,
            ],
            [
                'user_id' => null,
                'metadata' => [
                    'file_id' => $file->id,
                    'file_name' => $file->name,
                ],
                'created_at' => now(),
            ],
        );

        return response()->json([
            'data' => [
                'url' => $this->fileAccessService->generateDownloadUrl($file),
                'filename' => $file->name,
                'expires_in' => 3600,
            ],
        ]);
    }

    /**
     * List shares targeting the authenticated user.
     */
    public function withMe(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit', 25)), 200);

        $shares = $this->shareService->sharedWithMe($request->user(), $limit);

        return ShareResource::collection($shares)->response();
    }

    /**
     * List shares created by the authenticated user.
     */
    public function byMe(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit', 25)), 200);

        $shares = $this->shareService->sharedByMe($request->user(), $limit);

        return ShareResource::collection($shares)->response();
    }

    /**
     * Revoke (delete) a share.
     */
    public function revoke(Share $share): JsonResponse
    {
        $user = request()->user();

        $isOwner = ($share->file_id && $share->file?->owner_id === $user->id)
            || ($share->folder_id && $share->folder?->owner_id === $user->id);

        if (! $isOwner && ! $user->hasPermission('shares.delete-any')) {
            abort(404);
        }

        $shareId = $share->id;
        $resourceType = $share->isFileShare() ? 'file' : 'folder';
        $resourceId = $share->isFileShare()
            ? $share->file_id
            : ($share->folder?->uuid ?? $share->folder_id);

        $this->shareService->revoke($share);

        LogActivityJob::dispatch(
            $user->id,
            'revoke_share',
            $resourceType,
            (string) $resourceId,
            ['share_id' => $shareId],
            now()->toDateTimeString(),
        );

        return response()->json(null, 204);
    }

    /**
     * Update a share (currently: expiration date only).
     *
     * Only the share owner (shared_by) can update.
     */
    public function update(UpdateShareRequest $request, Share $share): JsonResponse
    {
        $user = $request->user();

        if ($share->shared_by !== $user->id) {
            abort(404);
        }

        $expiresAt = $request->validated('expires_at')
            ? Carbon::parse($request->validated('expires_at'))
            : null;

        $share->update(['expires_at' => $expiresAt]);

        $share->load(['file', 'folder', 'sharedBy', 'sharedWith']);

        return (new ShareResource($share))->response();
    }

    // ═══════════════════════════════════════════════════════
    // FOLDER SHARE — SUB-NAVIGATION & FILE ACCESS
    // ═══════════════════════════════════════════════════════

    /**
     * Browse a sub-folder within a folder share.
     *
     * Returns the sub-folder metadata + its children & files,
     * only if the sub-folder is a descendant of the shared root.
     *
     * @unauthenticated
     */
    public function browseFolderByToken(string $token, string $folderUuid): JsonResponse
    {
        $share = $this->shareService->findByToken($token);

        if (! $share || ! $share->isFolderShare()) {
            abort(404);
        }

        if ($share->isExpired()) {
            return response()->json(['message' => 'Share link has expired.'], 410);
        }

        $folder = Folder::query()->whereNull('deleted_at')->where('uuid', $folderUuid)->first();

        if (! $folder || ! $this->shareService->isDescendantFolder($share->folder_id, $folder)) {
            abort(404);
        }

        return response()->json(['data' => [
            'permission' => $share->permission,
            'folder' => new FolderResource($folder),
            'children' => FolderResource::collection(
                $folder->children()->whereNull('deleted_at')->orderBy('name')->get()
            ),
            'files' => FileResource::collection(
                $folder->files()->whereNull('deleted_at')->orderBy('name')->get()
            ),
        ]]);
    }

    /**
     * Access a file inside a folder share.
     *
     * Returns file metadata + presigned URL (inline disposition).
     * The share's `permission` field is included for FE to decide
     * whether to show a download button (UI-level only).
     *
     * @unauthenticated
     */
    public function fileByToken(string $token, string $fileId): JsonResponse
    {
        $share = $this->shareService->findByToken($token);

        if (! $share || ! $share->isFolderShare()) {
            abort(404);
        }

        if ($share->isExpired()) {
            return response()->json(['message' => 'Share link has expired.'], 410);
        }

        $file = File::query()->whereNull('deleted_at')->find($fileId);

        if (! $file || ! $this->shareService->isFileInsideSharedFolder($share->folder_id, $file)) {
            abort(404);
        }

        return response()->json(['data' => [
            'file' => new FileResource($file),
            'url' => $this->fileAccessService->generatePreviewUrl($file),
            'download_url' => $this->fileAccessService->generateDownloadUrl($file),
            'permission' => $share->permission,
        ]]);
    }
}
