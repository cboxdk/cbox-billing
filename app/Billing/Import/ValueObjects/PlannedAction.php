<?php

declare(strict_types=1);

namespace App\Billing\Import\ValueObjects;

use App\Billing\Import\Enums\ImportEntityType;
use App\Billing\Import\Enums\ImportOutcome;

/**
 * One resolved source record — what the importer decided (dry-run) or did (commit) with it. The
 * SAME shape carries both, so a planned action reads identically to its executed outcome: the
 * entity kind, the provider id + a human label, the outcome verb, the app model it resolved to
 * (when it resolved), and a one-line reason (a conflict's cause, a skip's "unchanged").
 */
readonly class PlannedAction
{
    public function __construct(
        public ImportEntityType $entity,
        public string $sourceId,
        public string $sourceLabel,
        public ImportOutcome $outcome,
        public ?string $appType = null,
        public ?string $appId = null,
        public ?string $message = null,
    ) {}

    public function withOutcome(ImportOutcome $outcome, ?string $message = null): self
    {
        return new self(
            $this->entity,
            $this->sourceId,
            $this->sourceLabel,
            $outcome,
            $this->appType,
            $this->appId,
            $message ?? $this->message,
        );
    }

    public function resolvedTo(string $appType, string $appId): self
    {
        return new self(
            $this->entity,
            $this->sourceId,
            $this->sourceLabel,
            $this->outcome,
            $appType,
            $appId,
            $this->message,
        );
    }
}
