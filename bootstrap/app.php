<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnforcePermission;
use App\Http\Middleware\EnsureAuthenticated;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureSandboxPlane;
use App\Http\Middleware\RecordsOperatorAudit;
use App\Http\Middleware\ResolveConsoleMode;
use App\Http\Middleware\SetsReferrerPolicy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

            // Token-authorized hosted checkout + customer portal: /billing/*. A strict
            // Referrer-Policy keeps the bearer-token URL from leaking to Stripe/3rd-parties.
            Route::middleware(['web', SetsReferrerPolicy::class])
                ->group(__DIR__.'/../routes/hosted.php');

            // Public, no-auth hosted order form: the CPQ /quote/{token} page a customer accepts
            // or declines. Self-contained + CSP-safe; the opaque token is the whole addressing
            // (an unknown token 404s). On the `web` group so session CSRF protects accept/decline.
            Route::middleware('web')
                ->group(__DIR__.'/../routes/quotes.php');

            // Public, no-auth embeddable storefront: the pricing table + paywall
            // (/pricing/{key}, /pricing/{key}/embed, /pricing/{key}/embed.js, /paywall).
            // Self-contained + CSP-safe; a pricing table's public key (or the paywall's org +
            // gated capability) is the whole addressing — no token, no provider auth gate.
            Route::middleware('web')
                ->group(__DIR__.'/../routes/storefront.php');

            // Public API reference: the OpenAPI 3.1 contract + a self-contained docs page
            // (/api/openapi.yaml, /api/openapi.json, /api/docs). No token — the contract is
            // public. Kept out of the /api/v1 token-authed group.
            Route::middleware('api')
                ->group(__DIR__.'/../routes/openapi.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TRUSTED PROXIES. The app ships a Dockerfile and a Helm chart, so it effectively always
        // runs behind a load balancer or ingress. Without this, `X-Forwarded-For` is ignored and
        // `$request->ip()` is the PROXY's address for every request — which quietly breaks three
        // things: (1) `throttle:cbox-webhook` and the license-activation limiter share a single
        // bucket across all callers, so one source can exhaust the limiter for every gateway
        // callback and every deployment heartbeat — an availability attack on the settlement path,
        // which is the exact thing those limiters exist to prevent; (2) the quote acceptance
        // e-signature records the balancer's IP instead of the signer's, and that field is what
        // makes the artifact evidentiary; (3) `$request->secure()` is false behind TLS
        // termination.
        //
        // Deliberately env-driven with no default: trusting proxies you do not control lets a
        // client spoof its own IP. Set TRUSTED_PROXIES to your balancer's address or CIDR, or to
        // `*` when the ingress is the only possible ingress path.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'auth.cbox' => EnsureAuthenticated::class,
            'billing.operator' => EnsureOperator::class,
            'billing.mode' => ResolveConsoleMode::class,
            'billing.audit' => RecordsOperatorAudit::class,
            'api.token' => AuthenticateApiToken::class,
            'idempotency' => EnforceIdempotency::class,
            'billing.permission' => EnforcePermission::class,
            'billing.sandbox' => EnsureSandboxPlane::class,
        ]);

        // PLANE-BEFORE-BINDING (console). `billing.mode` (ResolveConsoleMode) pushes the operator's
        // SELECTED environment onto the ambient BillingContext, and EnvironmentScope reads that
        // context on every query. But `SubstituteBindings` ships in the `web` GROUP, and a group's
        // middleware runs before the route's own — so, unsorted, every implicit model binding on a
        // console route ({subscription}, {testClock}, {invoice}, {plan}, {coupon}, …) resolved under
        // the ambient PRODUCTION plane no matter which sandbox the operator had selected. That is
        // both a usability bug (404s on legitimate sandbox rows) and a SAFETY bug (a production id
        // pasted into a mutating console action bound and mutated the PRODUCTION row while the
        // operator believed they were in a sandbox).
        //
        // The fix is Laravel's middleware PRIORITY list, which is what orders middleware across the
        // group/route boundary. Chaining the three relative inserts below lands the console's
        // authenticate → operator-gate → resolve-plane sequence immediately ahead of
        // `SubstituteBindings`, so a binding is always substituted inside the selected plane and a
        // cross-plane id simply does not resolve (404). Relative inserts are used rather than a
        // hard-coded `priority()` array so the framework's own default list keeps flowing through
        // untouched on upgrade. Order matters: read bottom-up — auth, then the operator-org gate,
        // then the plane, then the bindings.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureSandboxPlane::class);
        $middleware->prependToPriorityList(EnsureSandboxPlane::class, ResolveConsoleMode::class);
        $middleware->prependToPriorityList(ResolveConsoleMode::class, EnsureOperator::class);
        $middleware->prependToPriorityList(EnsureOperator::class, EnsureAuthenticated::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*'),
        );
    })->create();
