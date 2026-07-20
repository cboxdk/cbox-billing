<?php

declare(strict_types=1);

namespace App\Billing\Approvals\ValueObjects;

use App\Billing\Approvals\Contracts\ApprovableAction;
use App\Models\ApprovalRequest;

/**
 * The result of running a held action's {@see ApprovableAction::execute()}:
 * a one-line human `summary` of the money effect (for the flash + audit) and a JSON-safe
 * `data` bag persisted on the {@see ApprovalRequest::$result}. The data is the
 * durable proof of what the execution produced (a credit-note number, a wallet balance-after)
 * so the executed request can be shown — and re-shown on an idempotent re-approve — without
 * re-running anything.
 */
readonly class ApprovalOutcome
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $summary,
        public array $data = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['summary' => $this->summary] + $this->data;
    }
}
