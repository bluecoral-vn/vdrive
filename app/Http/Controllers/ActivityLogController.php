<?php

namespace App\Http\Controllers;

use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService) {}

    /**
     * List activity logs.
     * Users see their own logs; admin sees all.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min((int) $request->query('limit', '25'), 200);

        if ($user->hasPermission('activity-logs.view-any')) {
            $logs = $this->activityLogService->all($limit);
        } else {
            $logs = $this->activityLogService->forUser($user, $limit);
        }

        return ActivityLogResource::collection($logs)->response();
    }
}
