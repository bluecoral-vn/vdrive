<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct(private TagService $tagService) {}

    /**
     * List user's tags.
     */
    public function index(Request $request): JsonResponse
    {
        $tags = $this->tagService->listTags($request->user());

        return TagResource::collection($tags)->response();
    }

    /**
     * Create a new tag.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $tag = $this->tagService->createTag(
            $request->user(),
            $validated['name'],
            $validated['color'] ?? null,
        );

        return (new TagResource($tag))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a tag.
     */
    public function update(Request $request, Tag $tag): JsonResponse
    {
        if ($tag->user_id !== $request->user()->id) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $tag = $this->tagService->updateTag($tag, $validated);

        return (new TagResource($tag))->response();
    }

    /**
     * Delete a tag.
     */
    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        if ($tag->user_id !== $request->user()->id) {
            abort(404);
        }

        $this->tagService->deleteTag($tag);

        return response()->json(['message' => 'Tag deleted.']);
    }

    /**
     * Assign tags to resources.
     */
    public function assign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tag_ids' => ['required', 'array', 'min:1', 'max:50'],
            'tag_ids.*' => ['required', 'string', 'uuid'],
            'resource_type' => ['required', 'string', 'in:file,folder'],
            'resource_ids' => ['required', 'array', 'min:1', 'max:100'],
            'resource_ids.*' => ['required', 'string', 'max:36'],
        ]);

        $this->tagService->assign(
            $request->user(),
            $validated['tag_ids'],
            $validated['resource_type'],
            $validated['resource_ids'],
        );

        return response()->json(['message' => 'Tags assigned.']);
    }

    /**
     * Unassign tags from resources.
     */
    public function unassign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tag_ids' => ['required', 'array', 'min:1', 'max:50'],
            'tag_ids.*' => ['required', 'string', 'uuid'],
            'resource_type' => ['required', 'string', 'in:file,folder'],
            'resource_ids' => ['required', 'array', 'min:1', 'max:100'],
            'resource_ids.*' => ['required', 'string', 'max:36'],
        ]);

        $this->tagService->unassign(
            $request->user(),
            $validated['tag_ids'],
            $validated['resource_type'],
            $validated['resource_ids'],
        );

        return response()->json(['message' => 'Tags unassigned.']);
    }

    /**
     * List items for a tag.
     */
    public function items(Request $request, Tag $tag): JsonResponse
    {
        if ($tag->user_id !== $request->user()->id) {
            abort(404);
        }

        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = min((int) $request->query('limit', '15'), 100);

        $items = $this->tagService->tagItems($tag, $request->user(), $limit);

        return response()->json([
            'files' => FileResource::collection($items['files']),
            'folders' => FolderResource::collection($items['folders']),
            'meta' => [
                'files' => [
                    'next_cursor' => $items['files']->nextCursor()?->encode(),
                    'has_more' => $items['files']->hasMorePages(),
                ],
                'folders' => [
                    'next_cursor' => $items['folders']->nextCursor()?->encode(),
                    'has_more' => $items['folders']->hasMorePages(),
                ],
            ],
        ]);
    }
}
