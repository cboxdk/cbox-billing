<?php

declare(strict_types=1);

namespace App\Billing\Payments\Contracts;

use App\Billing\Payments\Dunning\DeclineCategory;
use App\Billing\Payments\Dunning\DeclineOutcome;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * Turns a gateway's opaque failure into the structured decline the adaptive dunning strategy
 * branches on: a canonical decline-code token and the {@see DeclineCategory}
 * it falls into. The engine's {@see PaymentResult} carries only a free-text `failureReason`
 * (the manual gateway's own string, or — for the Stripe adapter — the raw SDK exception
 * message), so classification is the one place that opaque reason is normalized. Kept behind a
 * contract so the retry service depends on the behaviour, not the concrete rule table, and a
 * host can compose a richer classifier (e.g. one fed structured `decline_code` data) without
 * touching the service.
 */
interface ClassifiesDeclines
{
    /** Classify a failed charge result into a canonical code + recovery category. */
    public function classify(PaymentResult $result): DeclineOutcome;

    /** Classify a raw decline code / reason string directly (the webhook / re-classify path). */
    public function classifyReason(?string $reason): DeclineOutcome;
}
