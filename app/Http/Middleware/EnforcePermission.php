<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\CurrentUser;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Federated-RBAC gate for the console. A route declares the `feature:action` slug it needs
 * (`->middleware('billing.permission:catalog:manage')`); this middleware decides whether the
 * authenticated Cbox ID principal may proceed, based on the `permissions` the principal
 * carries — the slugs Cbox ID assigns and delivers in the token/userinfo claims.
 *
 * DEFENSIVE + SAFE-BY-DEFAULT. Neither the id_token nor userinfo carries a `permissions`
 * (or `roles`) claim today — Cbox ID's token issuer emits only sub/email/name/org today —
 * so there is no signal to enforce against yet. The whole gate is therefore held behind the
 * `billing.rbac.enforce` flag (default FALSE):
 *
 *  - Flag OFF (today): INERT. It resolves the principal's permissions onto the request
 *    (`cbox.permissions`, for later use) and ALWAYS allows. It can never lock the operator
 *    surface out before the claim exists.
 *  - Flag ON (once Cbox ID emits the claim AND the operator opts in): strict deny-by-default.
 *    A principal without the required slug gets 403; an unauthenticated request 401.
 *
 * This is the honest rollout: enforcement lights up only when the upstream signal is real
 * AND the operator flips the flag. It presumes {@see EnsureAuthenticated} ran first (the
 * console routes are already behind `auth.cbox`).
 */
class EnforcePermission
{
    /** The request attribute the resolved permission slugs are attached to. */
    public const ATTRIBUTE = 'cbox.permissions';

    public function __construct(
        private readonly CurrentUser $current,
        private readonly Config $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $this->current->user();
        $permissions = $user === null ? [] : $user->permissions;

        // Always surface the resolved permissions so downstream code (and a future enforced
        // rollout) can read them without re-parsing claims.
        $request->attributes->set(self::ATTRIBUTE, $permissions);

        if (! $this->enforcing()) {
            return $next($request);
        }

        // Enforcing: deny-by-default against the required slug.
        if ($user === null) {
            abort(401);
        }

        abort_unless($user->hasPermission($permission), 403, "Missing required permission: {$permission}.");

        return $next($request);
    }

    private function enforcing(): bool
    {
        return (bool) $this->config->get('billing.rbac.enforce', false);
    }
}
