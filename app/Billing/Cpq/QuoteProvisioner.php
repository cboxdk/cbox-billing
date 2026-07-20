<?php

declare(strict_types=1);

namespace App\Billing\Cpq;

use App\Billing\Coupons\Contracts\RedeemsCoupons;
use App\Billing\Cpq\Contracts\ProvisionsFromQuote;
use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Subscription;
use Cbox\Billing\Subscription\ValueObjects\MinimumCommitment;
use Cbox\Billing\Subscription\ValueObjects\RampSchedule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Provisions the subscription an accepted {@see Quote} sells, through the engine
 * {@see SubscribesOrganizations} seam. IDEMPOTENT — the quote's `subscription_id` is the guard: a
 * quote that already provisioned returns its existing subscription, so a re-accept (a retried
 * request, a double click) never opens a second one.
 *
 * Modeling boundary (flagged): the durable engine {@see Subscription} row carries no columns for a
 * minimum commitment or a ramp schedule, so those committed terms are NOT written onto the
 * subscription — they live on the {@see Quote}, which is the CPQ contract of record and computes
 * the committed value through the engine {@see MinimumCommitment}
 * and {@see RampSchedule} value objects. Provisioning wires
 * the quote's PRIMARY plan line (seats = its quantity), the currency, and the order coupon; the
 * full contract (term, commitment, ramp, one-off and additional lines) is preserved on the quote
 * and its order form. Multi-line / add-on provisioning is a documented extension point.
 */
readonly class QuoteProvisioner implements ProvisionsFromQuote
{
    public function __construct(
        private ConnectionInterface $db,
        private SubscribesOrganizations $subscriptions,
        private RedeemsCoupons $coupons,
    ) {}

    public function provision(Quote $quote): Subscription
    {
        // Idempotency: a quote provisions exactly once.
        if ($quote->subscription_id !== null) {
            $existing = $quote->subscription;

            if ($existing instanceof Subscription) {
                return $existing;
            }
        }

        $organization = $quote->organization;

        if (! $organization instanceof Organization) {
            throw QuoteActionDenied::needsOrganization();
        }

        $plan = $this->primaryPlan($quote);
        $seats = $this->primarySeats($quote);

        return $this->db->transaction(function () use ($quote, $organization, $plan, $seats): Subscription {
            $subscription = $this->subscriptions->subscribe($organization, $plan, $seats, $quote->currency);

            // Carry the order coupon onto the subscription so its cycles are discounted as quoted.
            $coupon = $quote->coupon;
            if ($coupon instanceof Coupon) {
                $this->coupons->redeem($coupon, $subscription);
            }

            $quote->forceFill([
                'subscription_id' => $subscription->id,
                'provisioned_at' => Carbon::now(),
            ])->save();

            return $subscription;
        });
    }

    /** The plan the subscription is opened on: the first recurring plan line. */
    private function primaryPlan(Quote $quote): Plan
    {
        $line = $this->primaryLine($quote);
        $plan = $line?->plan;

        if (! $plan instanceof Plan) {
            throw QuoteActionDenied::needsPlanLine();
        }

        $plan->loadMissing('prices.tiers', 'product');

        return $plan;
    }

    private function primarySeats(Quote $quote): int
    {
        $line = $this->primaryLine($quote);

        return $line instanceof QuoteLine ? max(1, $line->quantity) : 1;
    }

    private function primaryLine(Quote $quote): ?QuoteLine
    {
        $quote->loadMissing('lines.plan');

        return $quote->lines
            ->first(static fn (QuoteLine $line): bool => $line->type === QuoteLineType::Plan && $line->recurring && $line->plan instanceof Plan);
    }
}
