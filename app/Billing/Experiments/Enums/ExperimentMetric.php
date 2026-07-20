<?php

declare(strict_types=1);

namespace App\Billing\Experiments\Enums;

/**
 * The event an experiment measures — and, one-to-one, the {@see App\Models\ExperimentConversion}
 * kind that records it. An experiment declares ONE primary metric; the results read model counts
 * the conversions whose `kind` equals that metric, so the primary metric and the conversion kind
 * are deliberately the same enum (no drift between "what we optimise for" and "what we count").
 *
 *  - `CheckoutStarted`   — the visitor reached the hosted checkout (a checkout session was minted
 *                          carrying the variant's attribution). A top-of-funnel intent signal.
 *  - `CheckoutCompleted` — the checkout settled (the gateway's settled webhook activated the
 *                          subscription). The real, bottom-of-funnel conversion.
 *
 * Both kinds are ALWAYS recorded as they happen (a start, then a completion); the primary metric
 * only decides which one the results and the significance test are computed over.
 */
enum ExperimentMetric: string
{
    case CheckoutStarted = 'checkout_started';
    case CheckoutCompleted = 'checkout_completed';

    /** A short human label for the console. */
    public function label(): string
    {
        return match ($this) {
            self::CheckoutStarted => 'Checkout started',
            self::CheckoutCompleted => 'Checkout completed',
        };
    }

    /** One-line description of what the metric measures, for the console form + dashboard. */
    public function description(): string
    {
        return match ($this) {
            self::CheckoutStarted => 'A visitor reached the hosted checkout (intent).',
            self::CheckoutCompleted => 'A checkout settled into a subscription (revenue).',
        };
    }
}
