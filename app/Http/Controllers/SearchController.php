<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Models\Tag;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private SearchService $searchService) {}

    /**
     * Search files with filters, respecting permissions.
     */
    public function index(Request $request): JsonResponse
    {
        // Cast string boolean from query string (e.g. "true"/"false") to native bool
        if ($request->has('favorite')) {
            $request->merge([
                'favorite' => filter_var($request->query('favorite'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'mime' => ['nullable'],
            'mime.*' => ['string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'owner' => ['nullable', 'integer', 'exists:users,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'tag' => ['nullable', 'string', 'uuid'],
            'favorite' => ['nullable', 'boolean'],
        ]);

        // Validate tag belongs to the authenticated user
        $tagId = null;
        if ($request->query('tag')) {
            $tag = Tag::query()
                ->where('uuid', $request->query('tag'))
                ->where('user_id', $request->user()->id)
                ->first();

            if (! $tag) {
                abort(422, 'Tag not found.');
            }

            $tagId = $tag->id;
        }

        $limit = min((int) $request->query('limit', '25'), 200);
        $mime = $request->query('mime');

        $files = $this->searchService->search(
            $request->user(),
            $request->query('query'),
            $mime,
            $request->query('from'),
            $request->query('to'),
            $request->query('owner') ? (int) $request->query('owner') : null,
            $limit,
            $tagId,
            (bool) $request->query('favorite', false),
        );

        return FileResource::collection($files)->response();
    }
}
