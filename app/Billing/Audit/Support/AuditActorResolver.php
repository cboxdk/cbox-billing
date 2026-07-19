<?php

declare(strict_types=1);

namespace App\Billing\Audit\Support;

use App\Auth\CurrentUser;
use App\Billing\Audit\Contracts\ResolvesAuditActor;
use App\Billing\Audit\ValueObjects\AuditActor;

/**
 * Resolves the audit actor from the console session. When a Cbox ID operator is signed in, the
 * event is attributed to their `sub` (with display name and request IP); otherwise — a
 * scheduled command, a queue worker, an unauthenticated path — it is the `system` sentinel.
 * The request is resolved lazily from the container so this works outside the HTTP kernel.
 */
readonly class AuditActorResolver implements ResolvesAuditActor
{
    public function __construct(private CurrentUser $current) {}

    public function resolve(): AuditActor
    {
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

    /** The request IP, when resolving inside an HTTP request; null in a console/job context. */
    private function ip(): ?string
    {
        return app()->bound('request') ? request()->ip() : null;
    }
}
