<?php

declare(strict_types=1);

namespace App\Billing\Experiments;

use App\Billing\Experiments\Contracts\AttributesConversions;
use App\Billing\Experiments\Enums\ExperimentMetric;
use App\Billing\Experiments\Enums\ExperimentStatus;
use App\Models\BillingSession;
use App\Models\Experiment;
use App\Models\ExperimentConversion;
use App\Models\ExperimentVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Attributes checkout conversions back to the variant a visitor was served — the second half of
 * the loop the {@see StorefrontExperimentResolver} started when it threaded a variant's
 * attribution onto the CTA deep-link.
 *
 * Two moments:
 *
 *  1. **checkout started** — `POST /api/v1/checkout-sessions` was called carrying the attribution
 *     triple (`cbox_exp` / `cbox_var` / `cbox_vid`). {@see recordCheckoutStart()} validates the
 *     variant belongs to a RUNNING experiment matching the key (deny-by-default — a stale link to
 *     a concluded experiment records nothing) and writes a `checkout_started` conversion, stamping
 *     the minted session so the settlement can find it.
 *  2. **checkout completed** — the gateway's settled webhook activated the subscription.
 *     {@see recordSettlement()} finds the started conversion(s) for that session and writes the
 *     matching `checkout_completed` conversion.
 *
 * Idempotency is the UNIQUE `(variant, visitor, kind)` index: a double checkout-start or a
 * re-delivered settlement webhook hits the constraint and is swallowed, so a conversion is counted
 * at most once. Both paths are best-effort — attribution must never break a checkout or a webhook —
 * so a failed write is contained, not propagated.
 */
readonly class ConversionAttribution implements AttributesConversions
{
    /** Record a checkout-started conversion from the attribution the checkout session carried. */
    public function recordCheckoutStart(BillingSession $session, string $experimentKey, int $variantId, string $visitorId): void
    {
        $visitorId = trim($visitorId);

        if ($visitorId === '') {
            return;
        }

        $variant = ExperimentVariant::query()->with('experiment')->find($variantId);

        if (! $variant instanceof ExperimentVariant) {
            return;
        }

        $experiment = $variant->experiment;

        // Deny-by-default: only a running experiment whose key matches the attribution accrues a
        // conversion — a link left over from a concluded/renamed experiment attributes nothing.
        if (! $experiment instanceof Experiment
            || $experiment->status !== ExperimentStatus::Running
            || $experiment->key !== $experimentKey) {
            return;
        }

        $this->write($experiment, $variant, $visitorId, ExperimentMetric::CheckoutStarted, $session->id);
    }

    /**
     * Record the checkout-completed conversion(s) for a settled session, matched to the earlier
     * checkout-started rows the session carried. Safe to call on every settlement (including
     * re-deliveries) — the unique index makes it idempotent.
     */
    public function recordSettlement(BillingSession $session): void
    {
        $starts = ExperimentConversion::query()
            ->where('billing_session_id', $session->id)
            ->where('kind', ExperimentMetric::CheckoutStarted->value)
            ->get();

        foreach ($starts as $start) {
            $variant = ExperimentVariant::query()->find($start->experiment_variant_id);

            if ($variant instanceof ExperimentVariant) {
                $this->writeById(
                    $start->experiment_id,
                    $variant->id,
                    $start->visitor_id,
                    ExperimentMetric::CheckoutCompleted,
                    $session->id,
                );
            }
        }
    }

    private function write(Experiment $experiment, ExperimentVariant $variant, string $visitorId, ExperimentMetric $kind, ?string $sessionId): void
    {
        $this->writeById($experiment->id, $variant->id, $visitorId, $kind, $sessionId);
    }

    private function writeById(int $experimentId, int $variantId, string $visitorId, ExperimentMetric $kind, ?string $sessionId): void
    {
        // A cheap pre-check keeps the common (already-recorded) path off the constraint; the unique
        // index is the real backstop for a concurrent double-fire.
        $exists = ExperimentConversion::query()
            ->where('experiment_variant_id', $variantId)
            ->where('visitor_id', $visitorId)
            ->where('kind', $kind->value)
            ->exists();

        if ($exists) {
            return;
        }

        try {
            ExperimentConversion::query()->create([
                'experiment_id' => $experimentId,
                'experiment_variant_id' => $variantId,
                'visitor_id' => $visitorId,
                'kind' => $kind->value,
                'billing_session_id' => $sessionId,
                'converted_at' => Carbon::now(),
            ]);
        } catch (QueryException) {
            // A concurrent writer inserted the unique row first — counted exactly once.
        }
    }
}
