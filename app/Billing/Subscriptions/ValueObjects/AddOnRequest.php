<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\ValueObjects;

use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\ValueObjects\AddOn;

/**
 * The validated inputs for attaching an add-on to a subscription (ADR-0012): an extra
 * recurring charge (`priceMinor` in `currency`) with an optional per-cycle
 * `creditAllotment`, billed either **aligned** to the base subscription's period or on
 * its own **independent** cycle (`anchorDay` / `anchorMonth` / `interval`). The service
 * projects it into the engine's {@see AddOn}.
 */
readonly class AddOnRequest
{
    public function __construct(
        public string $key,
        public int $priceMinor,
        public string $currency,
        public AddOnAlignment $alignment,
        public int $creditAllotment = 0,
        public ?int $anchorDay = null,
        public ?int $anchorMonth = null,
        public ?BillingInterval $interval = null,
    ) {}

    public function isIndependent(): bool
    {
        return $this->alignment === AddOnAlignment::Independent;
    }
}
