<?php

use App\Http\Middleware\EnsureAppInstalled;
use App\Http\Middleware\EnsureUserActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('trash:purge')->daily();
        $schedule->command('uploads:cleanup')->hourly();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ensure.active' => EnsureUserActive::class,
        ]);

        // Redirect to /setup.php when APP_INSTALLED=false
        $middleware->web(append: [
            EnsureAppInstalled::class,
        ]);

        // API-only app — never redirect unauthenticated users to a login page
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 401 Unauthenticated — always JSON (API-only app, no login route)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        // 403 Forbidden
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return response()->json(['message' => 'Forbidden.'], 403);
        });

        // 404 Not Found
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return response()->json(['message' => 'Not found.'], 404);
        });

        // 405 Method Not Allowed
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return response()->json(['message' => 'Method not allowed.'], 405);
        });

        // 422 Validation Error (clean, no stack trace)
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        });
    })->create();
