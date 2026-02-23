<?php

namespace App\Http\Controllers;

use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotaController extends Controller
{
    public function __construct(private QuotaService $quotaService) {}

    /**
     * Get current user's quota usage.
     */
    public function show(Request $request): JsonResponse
    {
        $quota = $this->quotaService->getQuota($request->user());

        return response()->json(['data' => $quota]);
    }
}
