<?php

namespace App\Http\Controllers;

use App\Http\Resources\FavoriteResource;
use App\Services\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function __construct(private FavoriteService $favoriteService) {}

    /**
     * List user's favorites.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'string', 'in:file,folder'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $limit = min((int) $request->query('limit', '50'), 500);

        $favorites = $this->favoriteService->list(
            $request->user(),
            $request->query('type'),
            $limit,
        );

        return FavoriteResource::collection($favorites)->response();
    }

    /**
     * Add a single favorite.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string', 'in:file,folder'],
            'resource_id' => ['required', 'string', 'max:36'],
        ]);

        $favorite = $this->favoriteService->add(
            $request->user(),
            $validated['resource_type'],
            $validated['resource_id'],
        );

        return (new FavoriteResource($favorite))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove a single favorite.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string', 'in:file,folder'],
            'resource_id' => ['required', 'string', 'max:36'],
        ]);

        $this->favoriteService->remove(
            $request->user(),
            $validated['resource_type'],
            $validated['resource_id'],
        );

        return response()->json(['message' => 'Favorite removed.']);
    }

    /**
     * Bulk add favorites.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string', 'in:file,folder'],
            'resource_ids' => ['required', 'array', 'min:1', 'max:100'],
            'resource_ids.*' => ['required', 'string', 'max:36'],
        ]);

        $count = $this->favoriteService->bulkAdd(
            $request->user(),
            $validated['resource_type'],
            $validated['resource_ids'],
        );

        return response()->json([
            'message' => "Added {$count} favorites.",
            'added' => $count,
        ]);
    }

    /**
     * Bulk remove favorites.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string', 'in:file,folder'],
            'resource_ids' => ['required', 'array', 'min:1', 'max:100'],
            'resource_ids.*' => ['required', 'string', 'max:36'],
        ]);

        $count = $this->favoriteService->bulkRemove(
            $request->user(),
            $validated['resource_type'],
            $validated['resource_ids'],
        );

        return response()->json([
            'message' => "Removed {$count} favorites.",
            'removed' => $count,
        ]);
    }
}
