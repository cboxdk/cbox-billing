<?php

declare(strict_types=1);

namespace App\Billing\Retention\ValueObjects;

use App\Billing\Retention\Enums\CancellationMode;

/**
 * A customer's cancellation intent: how to enact it ({@see CancellationMode}) plus the
 * captured churn reason and free-text feedback. The reason/feedback are persisted for
 * retention analytics regardless of which mode is chosen — including a pause, so a
 * would-be churn that was saved by a pause is still counted.
 */
readonly class CancellationRequest
{
    public function __construct(
        public CancellationMode $mode,
        public ?string $reason = null,
        public ?string $feedback = null,
    ) {}
}
