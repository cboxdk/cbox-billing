<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CurrentUser;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the console's current plane from the operator's session test-mode toggle and pushes
 * it onto the ambient {@see BillingContext}, so every list, detail and report the console
 * renders is scoped to the selected plane. Default is LIVE — the toggle is opt-in and its state
 * lives behind the {@see CurrentUser} session seam, never a raw session key here.
 */
readonly class ResolveConsoleMode
{
    public function __construct(
        private CurrentUser $current,
        private BillingContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->setMode($this->current->inTestMode() ? BillingMode::Test : BillingMode::Live);

        return $next($request);
    }
}
