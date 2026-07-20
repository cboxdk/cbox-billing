<?php

declare(strict_types=1);

namespace App\Billing\Approvals\Contracts;

use App\Billing\Approvals\Enums\ApprovalActionType;

/**
 * A factory that reconstructs a held {@see ApprovableAction} of one type from its persisted
 * JSON payload. The factory carries the domain-service dependencies (resolved from the
 * container); {@see build()} re-hydrates the target ids/scalars into a ready-to-run action.
 *
 * This is the single construction seam used by BOTH the direct path (a controller assembles
 * the payload from the validated request and builds the action) AND the approval path (the
 * executor rebuilds the action from the stored request) — so there is exactly one way an
 * action comes into being, and a direct run and an approved run are provably the same code.
 */
interface BuildsApprovableAction
{
    /** The catalog slug this factory builds. */
    public function type(): ApprovalActionType;

    /**
     * Re-hydrate the action from its payload. Throws a domain `*ActionDenied` (or model
     * not-found) when the payload references a target that no longer exists.
     *
     * @param  array<string, mixed>  $payload
     */
    public function build(array $payload): ApprovableAction;
}
