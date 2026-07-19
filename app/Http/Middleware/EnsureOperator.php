<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CurrentUser;
use App\Auth\OperatorAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The provider console's COARSE authorization gate (SEC-1). It runs AFTER {@see EnsureAuthenticated}
 * on the whole `auth.cbox` console group, so it can assume a valid Cbox ID session and only has to
 * decide authorization: is this principal one of the host's operators?
 *
 * A valid Cbox ID session is deliberately NOT sufficient — Cbox ID is a live, multi-tenant issuer
 * that also holds customer/end-user accounts. Admission requires the principal's organization (or
 * subject) to be allowlisted; see {@see OperatorAccess}. A session that is authenticated but not an
 * operator gets a clean 403 "not authorized for this console" page — never a redirect back to login
 * (that would loop, since the session is already valid).
 *
 * FAIL-CLOSED: with no allowlist configured every session is denied AND an actionable warning is
 * logged, so the deny-by-default posture surfaces to operators instead of failing silently.
 */
class EnsureOperator
{
    public function __construct(
        private readonly CurrentUser $current,
        private readonly OperatorAccess $operators,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->current->user();

        if ($this->operators->allows($user)) {
            return $next($request);
        }

        if (! $this->operators->isConfigured()) {
            Log::warning(
                'cbox-billing: the provider console is fail-closed — no operator allowlist is configured, '
                .'so every session is denied. Set CBOX_BILLING_OPERATOR_ORGS to your Cbox ID operator '
                .'organization id(s) (comma-separated) to admit your internal operators. '
                .'See docs/identity/console-access.md.'
            );
        } else {
            Log::warning('cbox-billing: denied provider-console access for a non-operator session.', [
                'sub' => $user?->sub,
                'org' => $user?->org,
            ]);
        }

        return response()->view('errors.operator', [], Response::HTTP_FORBIDDEN);
    }
}
