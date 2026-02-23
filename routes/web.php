<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA Catch-All Route
|--------------------------------------------------------------------------
|
| Serve the React SPA's index.html for all non-API routes.
| API routes (defined in api.php) take priority since they are
| registered under the /api prefix.
|
*/

Route::get('/{any?}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api|setup\.php).*$')
  ->withoutMiddleware([
      \Illuminate\Session\Middleware\StartSession::class,
      \Illuminate\View\Middleware\ShareErrorsFromSession::class,
      \Illuminate\Cookie\Middleware\EncryptCookies::class,
      \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
      \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
  ]);
