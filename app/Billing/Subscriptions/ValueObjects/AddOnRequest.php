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
 *
 * `expectedGrossDueMinor` is the "due now" GROSS the caller was shown at preview time. When set on
 * an APPLY, the service snapshots against it: if the freshly-computed prorated gross has drifted
 * (a period boundary crossed between preview and confirm), the apply is rejected as stale rather
 * than charging a different amount than previewed. Null on a preview, or an unguarded apply.
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
        public ?int $expectedGrossDueMinor = null,
    ) {}

    public function isIndependent(): bool
    {
        return $this->alignment === AddOnAlignment::Independent;
    }
}
