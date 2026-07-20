<?php

declare(strict_types=1);

namespace App\Billing\Approvals\ValueObjects;

/**
 * The human-readable summary a checker reads before deciding: a one-line `summary` of what
 * the held action will do, and an optional `before`/`after` diff (the same shape the audit
 * trail renders) so the checker sees the exact effect — e.g. an invoice status going
 * open → refunded, or a wallet balance 1 000 → 600. Rendered on the pending-queue screen.
 */
readonly class ApprovalDescription
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public string $summary,
        public array $before = [],
        public array $after = [],
    ) {}
}
