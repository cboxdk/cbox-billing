<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use DateTimeImmutable;

/**
 * The consequence of attaching an add-on: the prorated NET charge the engine computed, the
 * tax-aware GROSS actually collected (so preview == charge), the per-cycle credit allotment it
 * grants, its billing alignment, and the period end the proration ran to. A typed value object
 * (like {@see QuantityPreview}) rather than a loose array bag — serialized to the API/console
 * shape only at the edge via {@see toArray()}.
 */
readonly class AddOnPreview
{
    public function __construct(
        public Money $charge,
        public Money $grossDueNow,
        public int $allotment,
        public AddOnAlignment $alignment,
        public DateTimeImmutable $periodEnd,
    ) {}

    /**
     * The serialization-boundary shape (the console review + the management API body).
     *
     * @return array{charge_minor: int, gross_minor: int, currency: string, allotment: int, alignment: string, period_end: string}
     */
    public function toArray(): array
    {
        return [
            // The NET proration the engine computed (what the collector feeds the invoicer).
            'charge_minor' => $this->charge->minor(),
            // The tax-aware GROSS actually collected — the "Due now" a preview must show.
            'gross_minor' => $this->grossDueNow->minor(),
            'currency' => $this->charge->currency(),
            'allotment' => $this->allotment,
            'alignment' => $this->alignment->value,
            'period_end' => $this->periodEnd->format(DateTimeImmutable::ATOM),
        ];
    }
}
