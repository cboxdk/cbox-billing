<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Events;

use App\Billing\Payments\PaymentRetryService;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * A dunning payment attempt failed and a retry is scheduled. Raised by
 * {@see PaymentRetryService} at the non-terminal failure branch, once per
 * failed attempt, to feed `payment.failed`. The terminal (budget-exhausted) failure fires
 * {@see DunningExhausted} instead.
 */
readonly class PaymentFailed
{
    public function __construct(
        public Subscription $subscription,
        public Invoice $invoice,
        public int $attempt,
        public int $maxAttempts,
        public ?Carbon $nextAttemptAt,
        public ?string $reference,
    ) {}
}
