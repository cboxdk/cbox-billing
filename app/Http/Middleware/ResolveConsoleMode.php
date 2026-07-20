<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CurrentUser;
use App\Billing\Environments\EnvironmentRegistry;
use App\Billing\Mode\BillingContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the console's active ENVIRONMENT from the operator's session (the environment switcher)
 * and pushes it onto the ambient {@see BillingContext}, so every list, detail and report the
 * console renders is scoped to the selected plane. Default is production — the switcher is opt-in
 * and its state lives behind the {@see CurrentUser} session seam, never a raw session key here.
 */
readonly class ResolveConsoleMode
{
    public function __construct(
        private CurrentUser $current,
        private BillingContext $context,
        private EnvironmentRegistry $environments,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->setEnvironment($this->environments->resolve($this->current->activeEnvironmentKey()));

        return $next($request);
    }
}
