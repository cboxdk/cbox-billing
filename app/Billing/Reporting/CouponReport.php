<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Support\MoneyFormatter;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Read model for the Coupons screens — the routable coupon list and detail page. Projects
 * real {@see Coupon} rows with their redemption ledger into the shapes the coupons table
 * and detail page render (the discount summary, the redemption count/limit, the redemptions
 * list). No writes; every money value is formatted through {@see MoneyFormatter}.
 */
readonly class CouponReport
{
    /**
     * The paginated, optionally searched coupon list. Search matches code or name.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Coupon::query()->orderByDesc('id');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $query->where(function ($sub) use ($search): void {
                $sub->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate($perPage)
            ->through(fn (Coupon $coupon): array => $this->row($coupon))
            ->withQueryString();
    }

    /**
     * The detail shape for one coupon: its definition, redemption stats, and the recent
     * redemption ledger (with organization + subscription cross-links). Null when missing.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $coupon = Coupon::query()->find($id);

        if (! $coupon instanceof Coupon) {
            return null;
        }

        $redemptions = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->orderByDesc('redeemed_at')
            ->limit(50)
            ->get()
            ->map(static fn (CouponRedemption $redemption): array => [
                'organization_id' => $redemption->organization_id,
                'subscription_id' => $redemption->subscription_id,
                'redeemed_at' => $redemption->redeemed_at->format('j M Y H:i'),
            ])
            ->all();

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount' => $this->discountSummary($coupon),
            'duration' => $this->durationSummary($coupon),
            'scope' => $coupon->scope()->label(),
            'plan_keys' => $coupon->applies_to_plans ?? [],
            'status' => $this->status($coupon),
            'active' => $coupon->active,
            'archived' => $coupon->isArchived(),
            'times_redeemed' => $coupon->times_redeemed,
            'max_redemptions' => $coupon->max_redemptions,
            'max_redemptions_per_customer' => $coupon->max_redemptions_per_customer,
            'redeem_by' => $coupon->redeem_by?->format('j M Y'),
            'redemptions' => $redemptions,
            'redemptions_shown' => count($redemptions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount' => $this->discountSummary($coupon),
            'duration' => $this->durationSummary($coupon),
            'redemptions' => $this->redemptionSummary($coupon),
            'status' => $this->status($coupon),
        ];
    }

    /** A human discount summary, e.g. "20% off" or "25.00 kr. off". */
    private function discountSummary(Coupon $coupon): string
    {
        if ($coupon->discountKind() === CouponDiscountKind::Percent) {
            return sprintf('%d%% off', (int) ($coupon->percent_off ?? 0));
        }

        $amount = $coupon->fixedAmount();

        return $amount !== null ? MoneyFormatter::money($amount).' off' : 'fixed off';
    }

    private function durationSummary(Coupon $coupon): string
    {
        return match ($coupon->durationKind()->value) {
            'repeating' => sprintf('Repeating · %d periods', (int) ($coupon->duration_in_periods ?? 0)),
            'forever' => 'Forever',
            default => 'Once',
        };
    }

    private function redemptionSummary(Coupon $coupon): string
    {
        if ($coupon->max_redemptions === null) {
            return (string) $coupon->times_redeemed;
        }

        return sprintf('%d / %d', $coupon->times_redeemed, $coupon->max_redemptions);
    }

    /** The lifecycle status shown as a pill: archived / inactive / expired / exhausted / live. */
    private function status(Coupon $coupon): string
    {
        if ($coupon->isArchived()) {
            return 'archived';
        }

        if (! $coupon->active) {
            return 'inactive';
        }

        if ($coupon->isExpiredAt(Carbon::now())) {
            return 'expired';
        }

        if ($coupon->isExhausted()) {
            return 'exhausted';
        }

        return 'live';
    }
}
