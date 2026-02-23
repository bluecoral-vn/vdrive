<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDeleteRequest;
use App\Jobs\LogActivityJob;
use App\Models\Folder;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;

class BulkDeleteController extends Controller
{
    public function __construct(
        private TrashService $trashService,
    ) {}

    /**
     * Bulk soft-delete files and folders (move to trash).
     *
     * Entire operation is atomic (all-or-nothing).
     * If any item fails authorization, all deletes are rolled back.
     */
    public function __invoke(BulkDeleteRequest $request): JsonResponse
    {
        $fileIds = $request->validated('files', []);
        $folderUuids = $request->validated('folders', []);

        // Resolve folder UUIDs → numeric IDs
        $folderIds = [];
        if (! empty($folderUuids)) {
            $folderIds = Folder::query()
                ->whereIn('uuid', $folderUuids)
                ->pluck('id')
                ->all();
        }

        $result = $this->trashService->bulkDelete(
            $fileIds,
            $folderIds,
            $request->user(),
        );

        $totalItems = $result['deleted_files'] + $result['deleted_folders'];

        LogActivityJob::dispatch(
            $request->user()->id,
            'bulk_delete',
            'mixed',
            'bulk',
            [
                'files' => $fileIds,
                'folders' => $folderUuids,
                'deleted_files' => $result['deleted_files'],
                'deleted_folders' => $result['deleted_folders'],
            ],
            now()->toDateTimeString(),
        );

        return response()->json([
            'message' => "{$totalItems} items moved to trash",
        ]);
    }
}
