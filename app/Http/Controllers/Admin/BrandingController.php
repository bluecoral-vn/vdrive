<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBrandingRequest;
use App\Services\BrandingService;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    public function __construct(private BrandingService $brandingService) {}

    /**
     * Get current branding configuration (admin).
     */
    public function show(): JsonResponse
    {
        if (! auth()->user()->hasPermission('system-config.view')) {
            abort(403);
        }

        return response()->json([
            'data' => $this->brandingService->getBranding(),
        ]);
    }

    /**
     * Update branding configuration (admin, multipart/form-data).
     */
    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        if (! auth()->user()->hasPermission('system-config.update')) {
            abort(403);
        }

        $data = $request->only(['app_name', 'copyright_text', 'tag_line']);
        $logo = $request->file('logo');
        $favicon = $request->file('favicon');

        // Handle explicit deletion: delete_logo=true / delete_favicon=true
        if ($request->boolean('delete_logo')) {
            $this->brandingService->deleteAsset('logo');
        }

        if ($request->boolean('delete_favicon')) {
            $this->brandingService->deleteAsset('favicon');
        }

        $result = $this->brandingService->updateBranding($data, $logo, $favicon);

        return response()->json([
            'message' => 'Branding updated.',
            'data' => $result,
        ]);
    }

    /**
     * Get public branding configuration (no auth required).
     *
     * @unauthenticated
     */
    public function publicShow(): JsonResponse
    {
        $branding = $this->brandingService->getBranding();

        $branding['dev_credentials'] = config('app.dev_credentials') === 'show'
            ? [
                ['label' => 'Admin', 'email' => 'admin@bluecoral.vn', 'password' => 'admin'],
                ['label' => 'User',  'email' => 'user@bluecoral.vn',  'password' => 'user'],
            ]
            : null;

        $branding['demo_mode'] = app()->environment('demo')
            ? [
                'is_demo' => true,
                'reset_interval_minutes' => 15,
                'next_reset_at' => now()->ceilMinutes(15)->toIso8601String(),
            ]
            : null;

        return response()->json([
            'data' => $branding,
        ]);
    }
}
