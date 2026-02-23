<?php

namespace App\Providers;

use App\Services\PermissionContext;
use App\Services\PermissionContextBuilder;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind PermissionContext as a request-scoped singleton.
        // Built once per authenticated request, reused for all policy checks.
        // Using scoped() so it is flushed automatically after each request.
        $this->app->scoped(PermissionContext::class, function ($app) {
            $user = $app->make('request')->user();

            if ($user === null) {
                // Return an empty context for unauthenticated requests
                return new PermissionContext(
                    userId: 0,
                    permissions: [],
                    directFileShares: [],
                    folderShares: [],
                );
            }

            return $app->make(PermissionContextBuilder::class)->build($user);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'JWT'),
                );
            });
    }
}
