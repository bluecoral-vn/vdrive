<?php

namespace App\Http\Controllers;

use App\Http\Requests\AbortUploadRequest;
use App\Http\Requests\CompleteUploadRequest;
use App\Http\Requests\InitUploadRequest;
use App\Http\Requests\PresignPartRequest;
use App\Http\Resources\FileResource;
use App\Jobs\LogActivityJob;
use App\Models\Folder;
use App\Models\UploadSession;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    public function __construct(
        private UploadService $uploadService,
    ) {}

    /**
     * Initialize a multipart upload session.
     */
    public function init(InitUploadRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->uploadService->checkQuota($user, $request->validated('size_bytes'))) {
            return response()->json([
                'message' => 'Storage quota exceeded.',
            ], 422);
        }

        // Resolve folder UUID → model and check edit permission
        $folderUuid = $request->validated('folder_id');
        $folder = $folderUuid
            ? Folder::query()->where('uuid', $folderUuid)->first()
            : null;

        if ($folder) {
            $context = app(\App\Services\PermissionContext::class);
            if (! $context->canEditFolder($folder->id, $folder->owner_id, $folder->path)) {
                abort(403);
            }
        }

        $session = $this->uploadService->initUpload(
            $user,
            $request->validated('filename'),
            $request->validated('mime_type'),
            $request->validated('size_bytes'),
            $folder?->id,
        );

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'r2_object_key' => $session->r2_object_key,
                'expires_at' => $session->expires_at,
            ],
        ], 201);
    }

    /**
     * Generate a presigned URL for uploading a specific part.
     */
    public function presignPart(PresignPartRequest $request): JsonResponse
    {
        $session = UploadSession::query()->findOrFail($request->validated('session_id'));

        if ($session->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $session->isPending()) {
            return response()->json([
                'message' => 'Upload session is no longer active.',
            ], 422);
        }

        $url = $this->uploadService->presignPart(
            $session,
            $request->validated('part_number'),
        );

        return response()->json([
            'data' => [
                'url' => $url,
                'part_number' => $request->validated('part_number'),
            ],
        ]);
    }

    /**
     * Complete the multipart upload and create the file record.
     */
    public function complete(CompleteUploadRequest $request): JsonResponse
    {
        $session = UploadSession::query()->findOrFail($request->validated('session_id'));

        if ($session->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $session->isPending()) {
            return response()->json([
                'message' => 'Upload session is no longer active.',
            ], 422);
        }

        $file = $this->uploadService->completeUpload(
            $session,
            $request->validated('parts'),
        );

        $file->loadMissing('folder');

        LogActivityJob::dispatch(
            $request->user()->id,
            'create',
            'file',
            (string) $file->id,
            ['name' => $file->name, 'size_bytes' => $file->size_bytes, 'mime_type' => $file->mime_type],
            now()->toDateTimeString(),
        );

        return (new FileResource($file))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Abort a multipart upload session.
     */
    public function abort(AbortUploadRequest $request): JsonResponse
    {
        $session = UploadSession::query()->findOrFail($request->validated('session_id'));

        if ($session->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $session->isPending()) {
            return response()->json([
                'message' => 'Upload session is no longer active.',
            ], 422);
        }

        $this->uploadService->abortUpload($session);

        return response()->json(['message' => 'Upload aborted.']);
    }
}
