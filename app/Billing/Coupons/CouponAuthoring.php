<?php

declare(strict_types=1);

namespace App\Billing\Coupons;

use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use App\Billing\Coupons\Enums\CouponScope;
use App\Billing\Coupons\Exceptions\CouponActionDenied;
use App\Billing\Coupons\Exceptions\CouponAuthoringException;
use App\Billing\Coupons\ValueObjects\CouponDraft;
use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Support\Carbon;

/**
 * Authors coupons: the validated create / update / archive / delete write path the console
 * drives. Validation mirrors the engine {@see \Cbox\Billing\Pricing\ValueObjects\Coupon}
 * constructor invariants (a percentage is 1–100, a fixed discount needs an amount) and adds
 * the app-side rules the engine has no concept of (a repeating duration needs periods, a
 * plan scope needs plans, a limit is at least one).
 *
 * A redeemed coupon is archived, never deleted — {@see delete()} refuses to orphan a
 * redemption ledger or a live subscription binding.
 */
readonly class CouponAuthoring
{
    public function create(CouponDraft $draft): Coupon
    {
        $this->validate($draft, null);

        return Coupon::query()->create($this->attributes($draft));
    }

    public function update(Coupon $coupon, CouponDraft $draft): Coupon
    {
        $this->validate($draft, $coupon->id);

        $coupon->forceFill($this->attributes($draft))->save();

        return $coupon->refresh();
    }

    /** Soft-deactivate: the code stops redeeming, existing bindings keep their discount. */
    public function archive(Coupon $coupon): void
    {
        $coupon->forceFill(['active' => false, 'archived_at' => Carbon::now()])->save();
    }

    public function unarchive(Coupon $coupon): void
    {
        $coupon->forceFill(['active' => true, 'archived_at' => null])->save();
    }

    /** Hard-delete — refused once the coupon has ever been redeemed. */
    public function delete(Coupon $coupon): void
    {
        $redemptions = $coupon->redemptions()->count();

        if ($redemptions > 0) {
            throw CouponActionDenied::redeemed($coupon->code, $redemptions);
        }

        $coupon->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(CouponDraft $draft): array
    {
        $percent = $draft->kind === CouponDiscountKind::Percent ? $draft->percentOff : null;
        $amount = $draft->kind === CouponDiscountKind::FixedAmount ? $draft->amountOffMinor : null;
        $currency = $draft->kind === CouponDiscountKind::FixedAmount ? $draft->currency : null;
        $periods = $draft->duration === CouponDuration::Repeating ? $draft->durationInPeriods : null;
        $planKeys = $draft->scope === CouponScope::Plans ? $draft->planKeys : null;

        return [
            'code' => $this->normalizeCode($draft->code),
            'name' => $draft->name,
            'discount_type' => $draft->kind->value,
            'percent_off' => $percent,
            'amount_off_minor' => $amount,
            'currency' => $currency !== null ? strtoupper($currency) : null,
            'duration' => $draft->duration->value,
            'duration_in_periods' => $periods,
            'max_redemptions' => $draft->maxRedemptions,
            'max_redemptions_per_customer' => $draft->maxRedemptionsPerCustomer,
            'redeem_by' => $draft->redeemBy,
            'applies_to' => $draft->scope->value,
            'applies_to_plans' => $planKeys,
            'active' => $draft->active,
        ];
    }

    private function validate(CouponDraft $draft, ?int $ignoreId): void
    {
        $this->assertCodeUnique($this->normalizeCode($draft->code), $ignoreId);

        if ($draft->kind === CouponDiscountKind::Percent) {
            $percent = $draft->percentOff ?? 0;

            if ($percent < 1 || $percent > 100) {
                throw CouponAuthoringException::percentageOutOfRange($percent);
            }
        }

        if ($draft->kind === CouponDiscountKind::FixedAmount) {
            if ($draft->amountOffMinor === null || $draft->amountOffMinor < 1) {
                throw CouponAuthoringException::fixedNeedsAmount();
            }

            if ($draft->currency === null || $draft->currency === '') {
                throw CouponAuthoringException::fixedNeedsCurrency();
            }
        }

        if ($draft->duration === CouponDuration::Repeating && ($draft->durationInPeriods === null || $draft->durationInPeriods < 1)) {
            throw CouponAuthoringException::repeatingNeedsPeriods();
        }

        if ($draft->scope === CouponScope::Plans) {
            if ($draft->planKeys === []) {
                throw CouponAuthoringException::scopeNeedsPlans();
            }

            $this->assertPlansExist($draft->planKeys);
        }

        foreach ([$draft->maxRedemptions, $draft->maxRedemptionsPerCustomer] as $limit) {
            if ($limit !== null && $limit < 1) {
                throw CouponAuthoringException::limitBelowOne();
            }
        }
    }

    private function assertCodeUnique(string $code, ?int $ignoreId): void
    {
        $exists = Coupon::query()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw CouponActionDenied::duplicateCode($code);
        }
    }

    /**
     * @param  list<string>  $planKeys
     */
    private function assertPlansExist(array $planKeys): void
    {
        foreach ($planKeys as $key) {
            if (! Plan::query()->where('key', $key)->exists()) {
                throw CouponAuthoringException::unknownPlan($key);
            }
        }
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
