<?php

declare(strict_types=1);

namespace App\Billing\Approvals\ValueObjects;

/**
 * The resolved approval policy for one action type, parsed from
 * `config('billing.approvals.actions.<slug>')`:
 *
 *  - `enabled` — when false, the action always executes directly (the unchanged, no-regression
 *    path); the whole engine is opt-in per action type.
 *  - `thresholdMinor` — when enabled, an amount at or above this floor requires approval; null
 *    means "no amount gate" → every invocation of an enabled action requires approval.
 *  - `requiredApprovals` — how many DISTINCT checkers must approve before the held action runs
 *    (the M in an M-of-N maker-checker), at least one.
 */
readonly class ActionApprovalPolicy
{
    public function __construct(
        public bool $enabled,
        public ?int $thresholdMinor,
        public int $requiredApprovals,
    ) {}

    /**
     * Whether an invocation carrying `$amountMinor` trips this policy. Disabled → never.
     * Enabled with no threshold (or a non-money action) → always. Enabled with a threshold →
     * only at or above the floor.
     */
    public function requiresApproval(?int $amountMinor): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->thresholdMinor === null || $amountMinor === null) {
            return true;
        }

        return $amountMinor >= $this->thresholdMinor;
    }
}
