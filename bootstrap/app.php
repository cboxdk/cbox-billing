<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The enforcement API: /api/v1/*, token-authenticated and stateless.
            Route::middleware(['api', 'api.token'])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(__DIR__.'/../routes/api.php');

            // Public, signature-verified payment webhooks: /webhooks/{gateway}.
            Route::middleware('api')
                ->group(__DIR__.'/../routes/webhooks.php');

            // Token-authorized hosted checkout + customer portal: /billing/*.
            Route::middleware('web')
                ->group(__DIR__.'/../routes/hosted.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.cbox' => EnsureAuthenticated::class,
            'api.token' => AuthenticateApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*'),
        );
    })->create();
