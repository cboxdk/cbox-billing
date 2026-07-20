<?php

declare(strict_types=1);

namespace App\Billing\Audit\Support;

use App\Auth\CurrentUser;
use App\Billing\Api\ApiIdentity;
use App\Billing\Audit\Contracts\ResolvesAuditActor;
use App\Billing\Audit\ValueObjects\AuditActor;
use App\Http\Middleware\AuthenticateApiToken;

/**
 * Resolves the audit actor from the ambient request context, in priority order:
 *
 *  1. An authenticated API-token request — the mutation is attributed to the TOKEN identity
 *     (its `api-token:<id>` sub and name), so a token-authed refund/revoke/detach is recorded
 *     under the credential that performed it, not the `system` sentinel.
 *  2. An interactive console session — the signed-in Cbox ID operator (sub + name + IP).
 *  3. Neither (a scheduled command, a queue worker, an unauthenticated path) — the `system`
 *     sentinel, so an unattended action is recorded honestly rather than as a fabricated person.
 *
 * The request is resolved lazily from the container so this works outside the HTTP kernel.
 */
readonly class AuditActorResolver implements ResolvesAuditActor
{
    public function __construct(private CurrentUser $current) {}

    public function resolve(): AuditActor
    {
        $apiIdentity = $this->apiIdentity();

        if ($apiIdentity instanceof ApiIdentity) {
            return $apiIdentity->auditActor($this->ip());
        }

        $user = $this->current->check() ? $this->current->user() : null;

        if ($user === null || $user->sub === '') {
            return AuditActor::system();
        }

        return new AuditActor(
            sub: $user->sub,
            name: $user->name !== '' ? $user->name : null,
            ip: $this->ip(),
        );
    }

    /** The API-token identity attached by {@see AuthenticateApiToken}, when this is an API request. */
    private function apiIdentity(): ?ApiIdentity
    {
        if (! app()->bound('request')) {
            return null;
        }

        $identity = request()->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        return $identity instanceof ApiIdentity ? $identity : null;
    }

    /** The request IP, when resolving inside an HTTP request; null in a console/job context. */
    private function ip(): ?string
    {
        return app()->bound('request') ? request()->ip() : null;
    }
}
