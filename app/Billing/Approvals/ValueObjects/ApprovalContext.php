<?php

declare(strict_types=1);

namespace App\Billing\Approvals\ValueObjects;

use App\Models\ApprovalRequest;

/**
 * The threshold- and display-relevant facts about a held action, captured on the
 * {@see ApprovalRequest} row so the console can show WHAT is pending (the target
 * resource, the money at stake, the org) without rebuilding the action. `amountMinor` is what
 * the policy compares against a configured threshold; it is null for actions with no money
 * dimension (a suspend, a plan archive), which the policy treats as "always requires approval
 * when enabled".
 */
readonly class ApprovalContext
{
    public function __construct(
        public ?string $organizationId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
    ) {}
}
