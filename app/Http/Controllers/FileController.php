<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFileRequest;
use App\Http\Resources\FileResource;
use App\Jobs\GenerateThumbnailAndBlurhashJob;
use App\Jobs\LogActivityJob;
use App\Models\File;
use App\Models\Folder;
use App\Services\FileAccessService;
use App\Services\FileContentService;
use App\Services\FileService;
use App\Services\MoveService;
use App\Services\R2ClientService;
use App\Services\ThumbnailService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function __construct(
        private FileService $fileService,
        private FileAccessService $fileAccessService,
        private FileContentService $fileContentService,
        private ThumbnailService $thumbnailService,
        private TrashService $trashService,
        private MoveService $moveService,
    ) {}

    /**
     * Show a single file's metadata.
     */
    public function show(File $file): FileResource
    {
        $this->authorize('view', $file);

        $file->loadMissing('folder');

        return new FileResource($file);
    }

    /**
     * Update a file: rename and/or move to a different folder.
     */
    public function update(UpdateFileRequest $request, File $file): FileResource
    {
        $this->authorize('update', $file);

        $hasMove = $request->has('folder_id');
        $hasRename = $request->has('name');

        $perform = function () use ($request, &$file, $hasMove, $hasRename): void {
            // Handle move (folder_id change)
            if ($hasMove) {
                // Resolve folder UUID → numeric ID
                $folderUuid = $request->validated('folder_id');
                $targetFolderId = $folderUuid
                    ? Folder::query()->where('uuid', $folderUuid)->value('id')
                    : null;

                $file = $this->moveService->moveFile(
                    $file,
                    $targetFolderId,
                    $request->user(),
                );

                LogActivityJob::dispatch(
                    $request->user()->id,
                    'move',
                    'file',
                    (string) $file->id,
                    ['name' => $file->name, 'folder_id' => $folderUuid],
                    now()->toDateTimeString(),
                );
            }

            // Handle rename (name change)
            if ($hasRename) {
                $oldName = $file->name;
                $file = $this->fileService->rename($file, $request->validated('name'));

                LogActivityJob::dispatch(
                    $request->user()->id,
                    'update',
                    'file',
                    (string) $file->id,
                    ['old_name' => $oldName, 'new_name' => $file->name],
                    now()->toDateTimeString(),
                );
            }
        };

        // Wrap in transaction when both move and rename are requested
        if ($hasMove && $hasRename) {
            DB::transaction($perform);
        } else {
            $perform();
        }

        return new FileResource($file);
    }

    /**
     * Soft-delete a file (move to trash).
     */
    public function destroy(File $file): JsonResponse
    {
        $this->authorize('delete', $file);

        $this->trashService->softDeleteFile($file, request()->user());

        LogActivityJob::dispatch(
            request()->user()->id,
            'delete',
            'file',
            (string) $file->id,
            ['name' => $file->name],
            now()->toDateTimeString(),
        );

        return response()->json(null, 204);
    }

    /**
     * Get a presigned download URL for the file.
     */
    public function download(File $file): JsonResponse
    {
        $this->authorize('download', $file);

        $url = $this->fileAccessService->generateDownloadUrl($file);

        LogActivityJob::dispatch(
            request()->user()->id,
            'download',
            'file',
            (string) $file->id,
            ['name' => $file->name],
            now()->toDateTimeString(),
        );

        return response()->json([
            'data' => [
                'url' => $url,
                'filename' => $file->name,
                'expires_in' => 3600,
            ],
        ]);
    }

    /**
     * Stream file binary content from R2 through the server.
     *
     * Used by desktop clients when R2 presigned URLs are not
     * directly reachable from the local network.
     */
    public function stream(File $file): StreamedResponse
    {
        $this->authorize('download', $file);

        $r2 = app(R2ClientService::class);
        $result = $r2->client()->getObject([
            'Bucket' => $r2->bucket(),
            'Key'    => $file->r2_object_key,
        ]);

        LogActivityJob::dispatch(
            request()->user()->id,
            'download',
            'file',
            (string) $file->id,
            ['name' => $file->name, 'method' => 'stream'],
            now()->toDateTimeString(),
        );

        return response()->stream(function () use ($result) {
            echo $result['Body'];
        }, 200, [
            'Content-Type' => $file->mime_type,
            'Content-Disposition' => 'attachment; filename="' . $file->name . '"',
            'Content-Length' => $file->size_bytes,
        ]);
    }

    /**
     * Get a presigned preview URL for the file (browser-native rendering).
     */
    public function preview(File $file): JsonResponse
    {
        $this->authorize('preview', $file);

        $url = $this->fileAccessService->generatePreviewUrl($file);

        return response()->json([
            'data' => [
                'url' => $url,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'expires_in' => 600,
            ],
        ]);
    }

    /**
     * Get the text content of a file.
     *
     * Only allowed for text-based mime types and files ≤1MB.
     */
    public function content(File $file): JsonResponse
    {
        $this->authorize('content', $file);

        // Deleted file guard
        if ($file->isTrashed()) {
            abort(410);
        }

        // Mime type guard
        if (! $this->fileContentService->isTextMime($file->mime_type)) {
            abort(415);
        }

        // Size guard
        if ($file->size_bytes > $this->fileContentService->maxBytes()) {
            abort(413);
        }

        $content = $this->fileContentService->getTextContent($file);

        return response()->json([
            'data' => [
                'id' => $file->id,
                'name' => $file->name,
                'mime_type' => $file->mime_type,
                'content' => $content,
                'version' => $file->version,
                'checksum_sha256' => $file->checksum_sha256,
                'updated_at' => $file->updated_at,
            ],
        ]);
    }

    /**
     * Get a presigned URL for the file's thumbnail.
     */
    public function thumbnail(File $file): JsonResponse
    {
        $this->authorize('thumbnail', $file);

        // Thumbnail exists — return presigned URL
        if ($file->thumbnail_path !== null) {
            $url = $this->thumbnailService->generateThumbnailUrl($file);

            return response()->json([
                'data' => [
                    'url' => $url,
                    'width' => $file->thumbnail_width,
                    'height' => $file->thumbnail_height,
                    'blurhash' => $file->blurhash,
                    'mime_type' => 'image/webp',
                ],
            ]);
        }

        // Image but thumbnail not ready yet — dispatch regeneration and fallback to original
        if ($this->thumbnailService->supportsThumbnail($file->mime_type)) {
            GenerateThumbnailAndBlurhashJob::dispatch((string) $file->id);

            $url = $this->fileAccessService->generatePreviewUrl($file);

            return response()->json([
                'data' => [
                    'url' => $url,
                    'width' => null,
                    'height' => null,
                    'blurhash' => null,
                    'mime_type' => $file->mime_type,
                    'pending' => true,
                ],
            ]);
        }

        // Non-image file — no thumbnail available
        return response()->json(['data' => null]);
    }
}
