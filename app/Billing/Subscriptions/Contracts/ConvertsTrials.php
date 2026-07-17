<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\Contracts;

use App\Models\Subscription;
use DateTimeImmutable;

/**
 * Converts due free trials. On the scheduled trial pass it takes a `Trialing` subscription
 * whose trial end has passed and either converts it to a paying `Active` (raising the first
 * charge) or, when a payment method is required and none is on file, applies the configured
 * no-payment-method action. The console and jobs depend on this contract, never the
 * concrete service.
 */
interface ConvertsTrials
{
    /** The trial was not due, or the subscription is not a convertible trial — nothing done. */
    public const OUTCOME_SKIPPED = 'skipped';

    /** Converted `Trialing` → `Active`; the first invoice was raised. */
    public const OUTCOME_CONVERTED = 'converted';

    /** No payment method on file (and one was required): the subscription was canceled. */
    public const OUTCOME_CANCELED = 'canceled';

    /** No payment method on file (and one was required): the subscription was paused. */
    public const OUTCOME_PAUSED = 'paused';

    /**
     * Convert `$subscription` if its trial is due at `$at` (default: now). Returns one of the
     * `OUTCOME_*` constants. A non-`Trialing`, paused, or not-yet-due subscription is a
     * `SKIPPED` no-op, so the pass is safe to re-run.
     */
    public function convertDue(Subscription $subscription, ?DateTimeImmutable $at = null): string;
}
