<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\UpdateFolderRequest;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Jobs\LogActivityJob;
use App\Models\Folder;
use App\Services\FileService;
use App\Services\FolderService;
use App\Services\MoveService;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FolderController extends Controller
{
    public function __construct(
        private FolderService $folderService,
        private FileService $fileService,
        private TrashService $trashService,
        private MoveService $moveService,
    ) {}

    /**
     * List root folders and root files for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', '25'), 200);

        $folders = Folder::query()
            ->notTrashed()
            ->where('owner_id', $request->user()->id)
            ->whereNull('parent_id')
            ->orderBy('name')
            ->cursorPaginate($limit);

        $files = $this->fileService->rootFiles($request->user()->id, $limit);

        return response()->json([
            'folders' => FolderResource::collection($folders)->response()->getData(true),
            'files' => FileResource::collection($files)->response()->getData(true),
        ]);
    }

    /**
     * Create a new folder.
     */
    public function store(StoreFolderRequest $request): JsonResponse
    {
        // Resolve parent UUID → numeric ID
        $parentUuid = $request->validated('parent_id');
        $parent = $parentUuid
            ? Folder::query()->where('uuid', $parentUuid)->first()
            : null;

        $this->authorize('create', [Folder::class, $parent]);

        $folder = $this->folderService->create(
            $request->user(),
            $request->validated('name'),
            $parent?->id,
        );

        LogActivityJob::dispatch(
            $request->user()->id,
            'create',
            'folder',
            (string) $folder->uuid,
            ['name' => $folder->name, 'parent_id' => $folder->parent?->uuid],
            now()->toDateTimeString(),
        );

        return (new FolderResource($folder->load(['parent', 'owner'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single folder.
     */
    public function show(Folder $folder): FolderResource
    {
        $this->authorize('view', $folder);

        return new FolderResource($folder);
    }

    /**
     * Update a folder: rename and/or move to a different parent.
     */
    public function update(UpdateFolderRequest $request, Folder $folder): FolderResource
    {
        $this->authorize('update', $folder);

        $hasMove = $request->has('parent_id');
        $hasRename = $request->has('name');

        // Resolve parent UUID → numeric ID for move
        $targetParentId = null;
        if ($hasMove) {
            $parentUuid = $request->validated('parent_id');
            $targetParentId = $parentUuid
                ? Folder::query()->where('uuid', $parentUuid)->value('id')
                : null;
        }

        $perform = function () use ($request, &$folder, $hasMove, $hasRename, $targetParentId): void {
            // Handle move (parent_id change)
            if ($hasMove) {
                $folder = $this->moveService->moveFolder(
                    $folder,
                    $targetParentId,
                    $request->user(),
                );

                LogActivityJob::dispatch(
                    $request->user()->id,
                    'move',
                    'folder',
                    (string) $folder->uuid,
                    ['name' => $folder->name, 'parent_id' => $folder->parent?->uuid],
                    now()->toDateTimeString(),
                );
            }

            // Handle rename (name change) via service
            if ($hasRename) {
                $oldName = $folder->name;
                $folder = $this->folderService->rename($folder, $request->validated('name'));

                LogActivityJob::dispatch(
                    $request->user()->id,
                    'update',
                    'folder',
                    (string) $folder->uuid,
                    ['old_name' => $oldName, 'new_name' => $folder->name],
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

        return new FolderResource($folder->load('parent'));
    }

    /**
     * List children of a folder (cursor-paginated).
     */
    public function children(Request $request, Folder $folder): mixed
    {
        $this->authorize('view', $folder);

        $limit = min((int) $request->query('limit', '25'), 200);

        $children = $this->folderService->children($folder->id, $limit);

        return FolderResource::collection($children);
    }

    /**
     * Soft-delete a folder and all its descendants (move to trash).
     */
    public function destroy(Folder $folder): JsonResponse
    {
        $this->authorize('delete', $folder);

        $this->trashService->softDeleteFolder($folder, request()->user());

        LogActivityJob::dispatch(
            request()->user()->id,
            'delete',
            'folder',
            (string) $folder->uuid,
            ['name' => $folder->name],
            now()->toDateTimeString(),
        );

        return response()->json(null, 204);
    }

    /**
     * List files in a folder (cursor-paginated).
     */
    public function files(Request $request, Folder $folder): mixed
    {
        $this->authorize('view', $folder);

        $limit = min((int) $request->query('limit', '25'), 200);

        $files = $this->fileService->filesInFolder($folder->id, $limit);

        return FileResource::collection($files);
    }
}
