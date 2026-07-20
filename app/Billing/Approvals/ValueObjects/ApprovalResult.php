<?php

declare(strict_types=1);

namespace App\Billing\Approvals\ValueObjects;

use App\Billing\Approvals\ApprovalGate;
use App\Models\ApprovalRequest;

/**
 * What the {@see ApprovalGate} returns to a controller: either the
 * action executed directly (below threshold / policy disabled — the unchanged path) carrying
 * its {@see ApprovalOutcome}, or it was HELD for a second person carrying the pending
 * {@see ApprovalRequest}. The controller branches on {@see wasHeld()} to flash "submitted for
 * approval" instead of "done", and never has to know the policy details.
 */
readonly class ApprovalResult
{
    private function __construct(
        public bool $held,
        public ?ApprovalOutcome $outcome,
        public ?ApprovalRequest $request,
    ) {}

    /** The action ran now (policy did not require approval). */
    public static function executed(ApprovalOutcome $outcome): self
    {
        return new self(false, $outcome, null);
    }

    /** The action was captured as a pending request and did NOT take effect. */
    public static function held(ApprovalRequest $request): self
    {
        return new self(true, null, $request);
    }

    public function wasHeld(): bool
    {
        return $this->held;
    }
}
