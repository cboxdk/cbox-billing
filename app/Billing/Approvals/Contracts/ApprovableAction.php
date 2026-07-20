<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Contracts;

use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Approvals\ValueObjects\ApprovalContext;
use App\Billing\Approvals\ValueObjects\ApprovalDescription;
use App\Billing\Approvals\ValueObjects\ApprovalOutcome;

/**
 * A held action (the command in a maker-checker workflow). It wraps ONE sensitive mutation as
 * a self-contained, serializable unit so the SAME object can either run immediately (when the
 * policy does not require approval) or be captured, persisted, and run later by a second
 * person — executing identically either way.
 *
 * The invariant that makes the two-person rule sound: {@see execute()} is the one and only
 * place the mutation happens, and it is reached through the exact same call whether the action
 * runs directly or on approval. There is no second, un-gated code path.
 *
 *  - {@see payload()} is the JSON-safe parameter set persisted on the request and re-hydrated
 *    by the type's {@see BuildsApprovableAction} factory, so a held action is reconstructed
 *    byte-for-byte at approval time.
 *  - {@see validate()} re-checks the action can still run (the target still exists, still in a
 *    valid state) — run both before a direct execute AND again at approval time, because the
 *    world may have moved between capture and decision.
 *  - {@see describe()} is the human summary + before/after a checker reads.
 *  - {@see execute()} performs the mutation through the same domain service the direct path
 *    uses and returns the money effect; it records its own audit event, so an approved action
 *    is audited identically to a direct one.
 */
interface ApprovableAction
{
    /** The catalog slug this action belongs to (the policy + registry key). */
    public function type(): ApprovalActionType;

    /** The threshold/display facts captured on the held request (org, amount, target). */
    public function context(): ApprovalContext;

    /**
     * The JSON-safe parameters needed to reconstruct and execute this action later. No
     * secrets, no resolved models — only the ids/scalars the factory re-hydrates from.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * Re-validate that the action can still run in the current world, throwing a domain
     * `*ActionDenied` exception when it cannot (the target vanished, changed state, etc.).
     */
    public function validate(): void;

    /** The human summary + before/after diff a checker sees on the pending queue. */
    public function describe(): ApprovalDescription;

    /**
     * Perform the mutation through the underlying domain service and return its money effect.
     * MUST be idempotent-safe at the engine layer (an idempotency key, a status guard) so a
     * retried execution can never double-charge. Records its own audit event.
     */
    public function execute(): ApprovalOutcome;
}
