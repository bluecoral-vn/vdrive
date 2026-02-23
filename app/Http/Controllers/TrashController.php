<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Jobs\LogActivityJob;
use App\Models\File;
use App\Models\Folder;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    public function __construct(
        private TrashService $trashService,
    ) {}

    /**
     * Empty all trashed items for the authenticated user.
     */
    public function empty(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->trashService->emptyTrash($user);

        LogActivityJob::dispatch(
            $user->id,
            'empty_trash',
            'trash',
            '',
            [],
            now()->toDateTimeString(),
        );

        return response()->json(['message' => 'Trash is being emptied.'], 202);
    }

    /**
     * List trashed items for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min((int) $request->query('limit', '25'), 200);

        $files = $this->trashService->trashedFiles($user, $limit);
        $folders = $this->trashService->trashedFolders($user, $limit);

        return response()->json([
            'data' => [
                'files' => FileResource::collection($files)->response()->getData(true),
                'folders' => FolderResource::collection($folders)->response()->getData(true),
            ],
        ]);
    }

    /**
     * Restore a trashed file.
     */
    public function restoreFile(File $file): JsonResponse
    {
        $this->authorize('restore', $file);

        if (! $file->isTrashed()) {
            return response()->json(['message' => 'File is not in trash.'], 422);
        }

        $this->trashService->restoreFile($file);

        LogActivityJob::dispatch(
            request()->user()->id,
            'restore',
            'file',
            (string) $file->id,
            ['name' => $file->name],
            now()->toDateTimeString(),
        );

        return response()->json(['message' => 'File restored.']);
    }

    /**
     * Restore a trashed folder (with descendants).
     */
    public function restoreFolder(Folder $folder): JsonResponse
    {
        $this->authorize('restore', $folder);

        if (! $folder->isTrashed()) {
            return response()->json(['message' => 'Folder is not in trash.'], 422);
        }

        $this->trashService->restoreFolder($folder);

        LogActivityJob::dispatch(
            request()->user()->id,
            'restore',
            'folder',
            (string) $folder->uuid,
            ['name' => $folder->name],
            now()->toDateTimeString(),
        );

        return response()->json(['message' => 'Folder restored.']);
    }

    /**
     * Permanently delete a trashed file.
     */
    public function forceDeleteFile(File $file): JsonResponse
    {
        $this->authorize('forceDelete', $file);

        $fileId = $file->id;
        $fileName = $file->name;

        $this->trashService->forceDeleteFile($file);

        LogActivityJob::dispatch(
            request()->user()->id,
            'force_delete',
            'file',
            (string) $fileId,
            ['name' => $fileName],
            now()->toDateTimeString(),
        );

        return response()->json(null, 204);
    }

    /**
     * Permanently delete a trashed folder (with descendants).
     */
    public function forceDeleteFolder(Folder $folder): JsonResponse
    {
        $this->authorize('forceDelete', $folder);

        $folderUuid = $folder->uuid;
        $folderName = $folder->name;

        $this->trashService->forceDeleteFolder($folder);

        LogActivityJob::dispatch(
            request()->user()->id,
            'force_delete',
            'folder',
            (string) $folderUuid,
            ['name' => $folderName],
            now()->toDateTimeString(),
        );

        return response()->json(null, 204);
    }
}
