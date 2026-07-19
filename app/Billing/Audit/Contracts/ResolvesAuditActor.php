<?php

declare(strict_types=1);

namespace App\Billing\Audit\Contracts;

use App\Billing\Audit\ValueObjects\AuditActor;

/**
 * Resolves the actor for an audit event from the ambient request context. An interactive
 * console request resolves to the signed-in operator (sub + name + IP); a run with no operator
 * session — a scheduled command, a queued job — resolves to the {@see AuditActor::system()}
 * sentinel, so an unattended action is recorded honestly rather than attributed to a person.
 */
interface ResolvesAuditActor
{
    public function resolve(): AuditActor;
}
