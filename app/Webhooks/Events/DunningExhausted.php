<?php

declare(strict_types=1);

namespace App\Webhooks\Events;

use App\Billing\Payments\PaymentRetryService;
use App\Models\Invoice;
use App\Models\Subscription;

/**
 * Dunning ran out of retries for an invoice — the terminal action has run (immediate cancel or
 * left past-due). Raised by {@see PaymentRetryService} at the exhausted
 * branch to feed `dunning.exhausted`, the signal an integrator uses to trigger its own recovery
 * or offboarding flow.
 */
readonly class DunningExhausted
{
    public function __construct(
        public Subscription $subscription,
        public Invoice $invoice,
        public int $attempts,
    ) {}
}
