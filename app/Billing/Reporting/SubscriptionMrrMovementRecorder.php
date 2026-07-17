<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Retention\RetentionService;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Billing\Subscriptions\SubscriptionDepthService;
use App\Billing\Subscriptions\SubscriptionService;
use App\Billing\Support\SubscriptionRevenue;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use App\Models\SubscriptionMrrMovement;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Reporting\MrrMovement;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

/**
 * Records a row in the append-only {@see SubscriptionMrrMovement} log at each point a
 * subscription's contributing monthly-recurring amount actually changes. The lifecycle
 * services ({@see SubscriptionService},
 * {@see SubscriptionDepthService},
 * {@see RetentionService},
 * {@see CycleRenewalService}) call {@see record()} with the
 * amount before and after; this service classifies the movement and writes it idempotently.
 *
 * The "contributing" amount is the engine's state→MRR policy applied ({@see contributing()}):
 * a non-charging state (Trialing / Paused / Canceled) contributes zero, so movement is
 * measured on real recurring revenue, not plan list price. A trial subscribe therefore
 * records nothing (0→0); the new-logo movement lands when the trial converts (0→amount).
 *
 * The {@see $kind} classification mirrors the engine {@see MrrMovement} exactly (the engine
 * inlines it over a whole book; here it is applied to one transition), reusing
 * {@see MrrCalculator::contributes()} for the state policy so the app can never drift from
 * the engine's definition of what counts as revenue.
 */
readonly class SubscriptionMrrMovementRecorder
{
    public function __construct(private MrrCalculator $mrr) {}

    /**
     * The subscription's contributing monthly MRR in a given (or effective) lifecycle
     * state — the engine's state→MRR policy applied to its normalised monthly amount.
     */
    public function contributing(Subscription $subscription, ?SubscriptionStatus $status = null): Money
    {
        $status ??= $this->effectiveStatus($subscription);
        $monthly = SubscriptionRevenue::monthly($subscription);

        return $this->mrr->contributes($status) ? $monthly : Money::zero($monthly->currency());
    }

    /**
     * Record a movement from `$previous` to `$new` contributing MRR. A no-op when the two
     * are equal (nothing moved) or their currencies differ (a subscription bills in one
     * currency). `$returning` disambiguates a 0→positive transition as reactivation rather
     * than a new logo; when null it is derived from the win-back log for the org.
     *
     * Idempotent per (subscription, occurred_at, kind): a re-run upserts the same row.
     */
    public function record(Subscription $subscription, Money $previous, Money $new, ?Carbon $occurredAt = null, ?bool $returning = null): void
    {
        if ($previous->currency() !== $new->currency()) {
            return;
        }

        if ($previous->minor() === $new->minor()) {
            return;
        }

        $occurredAt ??= Carbon::now();
        $returning ??= $this->hasWinBack($subscription);
        $kind = $this->classify($previous, $new, $returning);

        SubscriptionMrrMovement::query()->updateOrCreate(
            [
                'subscription_id' => $subscription->id,
                'occurred_at' => $occurredAt,
                'kind' => $kind,
            ],
            [
                'organization_id' => $subscription->organization_id,
                'currency' => $new->currency(),
                'previous_mrr_minor' => $previous->minor(),
                'new_mrr_minor' => $new->minor(),
            ],
        );
    }

    /**
     * Classify a single prev→new transition, mirroring {@see MrrMovement}: a zero→positive
     * move is a new logo (or a reactivation when returning); positive→zero is churn; a rise
     * between two positive amounts is expansion, a fall is contraction. Equal amounts never
     * reach here ({@see record()} filters them).
     */
    private function classify(Money $previous, Money $new, bool $returning): string
    {
        if ($previous->isZero() && $new->isPositive()) {
            return $returning ? SubscriptionMrrMovement::KIND_REACTIVATION : SubscriptionMrrMovement::KIND_NEW;
        }

        if ($previous->isPositive() && $new->isZero()) {
            return SubscriptionMrrMovement::KIND_CHURN;
        }

        return $new->minor() > $previous->minor()
            ? SubscriptionMrrMovement::KIND_EXPANSION
            : SubscriptionMrrMovement::KIND_CONTRACTION;
    }

    /** A paused subscription reads as {@see SubscriptionStatus::Paused} even while stored Active. */
    private function effectiveStatus(Subscription $subscription): SubscriptionStatus
    {
        return $subscription->isPaused() ? SubscriptionStatus::Paused : $subscription->status;
    }

    /** Whether the org has a recorded win-back — the returning signal for a 0→positive move. */
    private function hasWinBack(Subscription $subscription): bool
    {
        return SubscriptionCancellation::query()
            ->where('organization_id', $subscription->organization_id)
            ->where('mode', SubscriptionCancellation::MODE_REACTIVATE)
            ->exists();
    }
}
