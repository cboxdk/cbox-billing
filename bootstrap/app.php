<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnforcePermission;
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
            // The enforcement + management API: /api/v1/*, token-authenticated and
            // stateless. Per-token throttling is tiered INSIDE the route file — the
            // enforcement hot path (`throttle:cbox-enforcement`) runs hotter than the
            // management surface (`throttle:cbox-management`) — so each group carries its
            // own limiter rather than one blanket ceiling for both.
            Route::middleware(['api', 'api.token'])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(__DIR__.'/../routes/api.php');

            // Public, signature-verified payment webhooks: /webhooks/{gateway}. Rate-limited
            // per source IP (`throttle:cbox-webhook`) so a flood of forged callbacks cannot
            // exhaust the settlement path; authenticity is still the gateway signature.
            Route::middleware(['api', 'throttle:cbox-webhook'])
                ->group(__DIR__.'/../routes/webhooks.php');

            // The optional, unauthenticated license activation heartbeat: /api/v1/license/*.
            // Rate-limited; the deployment id is the credential (see the route file).
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(__DIR__.'/../routes/licensing.php');

            // Token-authorized hosted checkout + customer portal: /billing/*.
            Route::middleware('web')
                ->group(__DIR__.'/../routes/hosted.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.cbox' => EnsureAuthenticated::class,
            'api.token' => AuthenticateApiToken::class,
            'idempotency' => EnforceIdempotency::class,
            'billing.permission' => EnforcePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*'),
        );
    })->create();
