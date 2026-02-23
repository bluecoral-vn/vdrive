<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect all web requests to /setup.php when APP_INSTALLED=false.
 *
 * Skips: setup.php itself and /api/* routes (handled by api.php).
 */
class EnsureAppInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.installed')) {
            return $next($request);
        }

        // Don't redirect setup.php or API routes
        $path = $request->path();
        if ($path === 'setup.php' || str_starts_with($path, 'api/') || $path === 'api') {
            return $next($request);
        }

        return redirect('/setup.php');
    }
}
