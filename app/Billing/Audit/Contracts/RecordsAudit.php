<?php

declare(strict_types=1);

namespace App\Billing\Audit\Contracts;

use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditActor;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Models\OperatorAuditEvent;

/**
 * The single seam every operator mutation records through. Appending is atomic and serialized:
 * the recorder assigns the next monotonic sequence, links the hash chain, and inserts one
 * immutable row. A caller supplies the typed action, the target, a human summary, and an
 * optional before/after metadata diff; the actor defaults to the current operator session (or
 * the `system` sentinel for an unattended run) unless one is passed explicitly.
 *
 * NEVER pass a secret in `$metadata` — token plaintext, a license key, a certificate document.
 * Record the fact plus a reference (an id, a fingerprint), never the value.
 */
interface RecordsAudit
{
    /**
     * Append one audit event and return the persisted, hash-linked row.
     *
     * @param  array<string, mixed>  $metadata  a JSON-safe diff/context bag (`before`/`after` keys are rendered as a diff); no secrets
     */
    public function record(
        AuditAction $action,
        AuditTarget $target,
        string $summary,
        array $metadata = [],
        ?AuditActor $actor = null,
        ?bool $livemode = null,
    ): OperatorAuditEvent;
}
