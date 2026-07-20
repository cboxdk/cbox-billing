<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a SANDBOX plane for the console flows whose objects only ever exist in a sandbox — the
 * test-clock manager. A test clock is always a sandbox object, but the plane must be the
 * CURRENTLY-SELECTED one: when the console has switched to a named sandbox, {@see ResolveConsoleMode}
 * has already set it and this is a no-op; forcing the default sandbox here would collapse a named
 * sandbox onto the default one, hiding its own clocks and subscriptions. So the fallback to the
 * default sandbox applies only when the console is sitting on the production/live plane.
 *
 * This lives in MIDDLEWARE rather than in the controller because it changes the plane, and the plane
 * must be completely settled before `SubstituteBindings` resolves `{testClock}` — a controller-side
 * switch runs AFTER the binding, so the clock was looked up under production, 404'd, and the plane
 * the handler then ran in disagreed with the plane the binding used. It is registered in the
 * middleware PRIORITY list (bootstrap/app.php) immediately after {@see ResolveConsoleMode} and
 * immediately before binding substitution.
 */
readonly class EnsureSandboxPlane
{
    public function __construct(private BillingContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->isTest()) {
            $this->context->setMode(BillingMode::Test);
        }

        return $next($request);
    }
}
