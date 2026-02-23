<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkMoveRequest;
use App\Jobs\LogActivityJob;
use App\Models\Folder;
use App\Services\MoveService;
use Illuminate\Http\JsonResponse;

class MoveController extends Controller
{
    public function __construct(
        private MoveService $moveService,
    ) {}

    /**
     * Bulk move files and folders to a target folder (or root).
     *
     * Entire operation is wrapped in a DB transaction.
     * If any item fails validation, all moves are rolled back.
     */
    public function __invoke(BulkMoveRequest $request): JsonResponse
    {
        $fileIds = $request->validated('files', []);
        $folderUuids = $request->validated('folders', []);
        $targetFolderUuid = $request->validated('target_folder_id');

        // Resolve folder UUIDs → numeric IDs
        $folderIds = [];
        if (! empty($folderUuids)) {
            $folderIds = Folder::query()
                ->whereIn('uuid', $folderUuids)
                ->pluck('id')
                ->all();
        }

        // Resolve target folder UUID → numeric ID
        $targetFolderId = $targetFolderUuid
            ? Folder::query()->where('uuid', $targetFolderUuid)->value('id')
            : null;

        $result = $this->moveService->bulkMove(
            $fileIds,
            $folderIds,
            $targetFolderId,
            $request->user(),
        );

        LogActivityJob::dispatch(
            $request->user()->id,
            'bulk_move',
            'mixed',
            'bulk',
            [
                'files' => $fileIds,
                'folders' => $folderUuids,
                'target_folder_id' => $targetFolderUuid,
                'moved_files' => $result['moved_files'],
                'moved_folders' => $result['moved_folders'],
            ],
            now()->toDateTimeString(),
        );

        return response()->json(['data' => $result]);
    }
}
