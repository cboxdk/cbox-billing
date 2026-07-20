<?php

declare(strict_types=1);

namespace App\Billing\Coupons;

use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponScope;
use App\Billing\Coupons\Exceptions\CouponRedemptionDenied;
use App\Billing\Coupons\ValueObjects\CouponDiscount;
use App\Billing\Webhooks\Events\CouponRedeemed as CouponRedeemedEvent;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCoupon;
use Cbox\Billing\Money\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Validates and redeems a promo code — the money path's deny-by-default gate. It resolves a
 * code (case-insensitive), refuses an unknown / inactive / expired / over-limit /
 * not-applicable code with a specific {@see CouponRedemptionDenied}, previews the discount
 * through the engine ({@see CouponDiscounter}), and — when a subscribe/checkout commits —
 * records the redemption and binds the coupon to the subscription ATOMICALLY under a row
 * lock on the coupon (mirrors the seat-assign lock: `SELECT … FOR UPDATE` the stable coupon
 * row, re-check the caps against the settled count, then insert), so concurrent redeemers
 * can never push `times_redeemed` past `max_redemptions`.
 */
readonly class CouponRedeemer
{
    public function __construct(
        private ConnectionInterface $db,
        private CouponDiscounter $discounter,
    ) {}

    /**
     * Resolve and validate a code for a plan/currency/organization, returning the coupon.
     * Throws {@see CouponRedemptionDenied} (deny-by-default) on any failing check.
     */
    public function validate(string $code, Plan $plan, string $currency, string $organizationId, ?Carbon $at = null): Coupon
    {
        $at ??= Carbon::now();
        $normalized = strtoupper(trim($code));

        $coupon = Coupon::query()->where('code', $normalized)->first();

        if (! $coupon instanceof Coupon) {
            throw CouponRedemptionDenied::unknown($code);
        }

        if (! $coupon->active || $coupon->isArchived()) {
            throw CouponRedemptionDenied::inactive($coupon->code);
        }

        if ($coupon->isExpiredAt($at)) {
            throw CouponRedemptionDenied::expired($coupon->code);
        }

        if ($coupon->isExhausted()) {
            throw CouponRedemptionDenied::limitReached($coupon->code);
        }

        if (! $coupon->appliesToPlan($plan)) {
            throw CouponRedemptionDenied::notApplicable($coupon->code);
        }

        $this->assertCurrency($coupon, $currency);
        $this->assertCustomerLimit($coupon, $organizationId);

        return $coupon;
    }

    /**
     * The discount this coupon would apply to `$net` — the figure a checkout/subscribe
     * previews (and, by construction, is charged). Null when the coupon does not reduce it.
     */
    public function discountFor(Coupon $coupon, Money $net, ?Carbon $at = null): ?CouponDiscount
    {
        return $this->discounter->forCoupon($coupon, $net, ($at ?? Carbon::now())->toDateTimeImmutable());
    }

    /**
     * Record a redemption of `$coupon` by `$subscription`'s organization and bind it to the
     * subscription — atomically, under a lock on the coupon row so `max_redemptions` and the
     * per-customer cap hold under concurrency. Returns the durable binding. The per-plan /
     * currency / expiry checks are the caller's ({@see validate()}); the LIMIT checks are
     * re-made here against the locked, settled counts.
     */
    public function redeem(Coupon $coupon, Subscription $subscription, ?Carbon $at = null): SubscriptionCoupon
    {
        $at ??= Carbon::now();

        $binding = $this->db->transaction(function () use ($coupon, $subscription, $at): SubscriptionCoupon {
            // Serialize every redemption of this coupon on the STABLE coupon row (M2): a
            // FOR UPDATE COUNT over the redemption rows locks nothing at zero, so two
            // concurrent first-redeems both read 0 and both insert past a limit of 1. Locking
            // the coupon row first forces them into a queue and decides the cap against a
            // settled count.
            $locked = Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Coupon) {
                throw CouponRedemptionDenied::unknown($coupon->code);
            }

            if ($locked->isExhausted()) {
                throw CouponRedemptionDenied::limitReached($locked->code);
            }

            $this->assertCustomerLimit($locked, $subscription->organization_id);

            CouponRedemption::query()->create([
                'coupon_id' => $locked->id,
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'redeemed_at' => $at,
            ]);

            $locked->forceFill(['times_redeemed' => $locked->times_redeemed + 1])->save();

            return $this->bind($locked, $subscription);
        });

        // Redemption committed: fan out `coupon.redeemed` (idempotency-keyed on coupon + subscription).
        event(new CouponRedeemedEvent($coupon, $subscription));

        return $binding;
    }

    /** Snapshot the coupon onto the subscription as a durable binding (replacing any prior). */
    private function bind(Coupon $coupon, Subscription $subscription): SubscriptionCoupon
    {
        $remaining = $coupon->durationKind()->openingRemaining($coupon->duration_in_periods);

        return SubscriptionCoupon::query()->updateOrCreate(
            ['subscription_id' => $subscription->id],
            [
                'coupon_id' => $coupon->id,
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'percent_off' => $coupon->percent_off,
                'amount_off_minor' => $coupon->amount_off_minor,
                'currency' => $coupon->currency,
                'duration' => $coupon->duration,
                'remaining_periods' => $remaining,
            ],
        );
    }

    private function assertCurrency(Coupon $coupon, string $currency): void
    {
        if ($coupon->discountKind() === CouponDiscountKind::FixedAmount
            && $coupon->currency !== null
            && strtoupper($currency) !== $coupon->currency) {
            throw CouponRedemptionDenied::currencyMismatch($coupon->code, $coupon->currency, strtoupper($currency));
        }
    }

    private function assertCustomerLimit(Coupon $coupon, string $organizationId): void
    {
        if ($coupon->max_redemptions_per_customer === null) {
            return;
        }

        $used = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('organization_id', $organizationId)
            ->count();

        if ($used >= $coupon->max_redemptions_per_customer) {
            throw CouponRedemptionDenied::customerLimitReached($coupon->code);
        }
    }

    /** Marker so a `plans`-scoped coupon is obvious in reads. */
    public function isPlanScoped(Coupon $coupon): bool
    {
        return $coupon->scope() === CouponScope::Plans;
    }
}
