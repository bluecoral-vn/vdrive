<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailLogResource;
use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    /**
     * List email logs (cursor-paginated, filterable by status).
     */
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->hasPermission('email-logs.view')) {
            abort(403);
        }

        $limit = min((int) ($request->query('limit', 25)), 200);

        $query = EmailLog::query()->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $logs = $query->cursorPaginate($limit);

        return EmailLogResource::collection($logs)->response();
    }

    /**
     * Show a single email log entry.
     */
    public function show(EmailLog $emailLog): JsonResponse
    {
        if (! auth()->user()->hasPermission('email-logs.view')) {
            abort(403);
        }

        return (new EmailLogResource($emailLog))->response();
    }
}
