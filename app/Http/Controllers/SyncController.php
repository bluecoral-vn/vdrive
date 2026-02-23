<?php

namespace App\Http\Controllers;

use App\Http\Resources\SyncEventResource;
use App\Models\SyncEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * GET /api/sync/delta — Fetch sync events after a given cursor.
     *
     * Query params:
     *   - cursor: last known event ID (default: 0)
     *   - limit:  max events per page (default: 100, max: 500)
     */
    public function delta(Request $request): JsonResponse
    {
        $request->validate([
            'cursor' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $cursor = (int) $request->input('cursor', 0);
        $limit = (int) $request->input('limit', 100);

        $events = SyncEvent::query()
            ->where('user_id', $request->user()->id)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit + 1) // Fetch one extra to determine has_more
            ->get();

        $hasMore = $events->count() > $limit;

        if ($hasMore) {
            $events = $events->take($limit);
        }

        $nextCursor = $events->isNotEmpty() ? $events->last()->id : $cursor;

        return response()->json([
            'data' => SyncEventResource::collection($events),
            'meta' => [
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * GET /api/sync/status — Get the latest cursor and total events for polling.
     */
    public function status(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $latestCursor = SyncEvent::query()
            ->where('user_id', $userId)
            ->max('id') ?? 0;

        $totalEvents = SyncEvent::query()
            ->where('user_id', $userId)
            ->count();

        return response()->json([
            'latest_cursor' => (int) $latestCursor,
            'total_events' => $totalEvents,
        ]);
    }
}
