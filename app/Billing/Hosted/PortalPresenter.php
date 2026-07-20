<?php

declare(strict_types=1);

namespace App\Billing\Hosted;

use App\Billing\Coupons\Contracts\DiscountsAmounts;
use App\Billing\Reporting\UsageReport;
use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\ValueObjects\SeatBreakdown;
use App\Billing\Storefront\PricingTablePresenter;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Billing\Support\MoneyFormatter;
use App\Http\Controllers\Hosted\PortalController;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The self-service portal's read model (mirrors {@see PricingTablePresenter}):
 * projects the catalog, invoices, a plan-change preview, current usage, and the seat breakdown
 * into the plain shapes the portal page + its JSON endpoints render, so the fat
 * {@see PortalController} stays a thin HTTP adapter. No writes —
 * every amount is preformatted through the single {@see MoneyFormatter} seam so the client never
 * re-derives money with a hardcoded /100 + locale.
 */
readonly class PortalPresenter
{
    public function __construct(
        private DiscountsAmounts $discounter,
        private UsageReport $usage,
        private ManagesSeats $seats,
    ) {}

    /**
     * The active plans a subscriber can switch to — every plan priced in `$currency` except the
     * one they are on — with the price preformatted.
     *
     * @return list<array<string, mixed>>
     */
    public function availablePlans(string $currency, ?Subscription $subscription): array
    {
        $currentKey = $subscription?->plan?->key;

        return array_values(Plan::query()
            ->with('prices')
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Plan $plan): bool => $plan->key !== $currentKey && $plan->prices->contains('currency', $currency))
            ->map(static function (Plan $plan) use ($currency): array {
                $price = $plan->priceFor($currency);

                return [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price' => MoneyFormatter::money($price),
                    'minor' => $price->minor(),
                ];
            })
            ->all());
    }

    /**
     * The org's most recent invoices (newest first, capped) for the billing-history panel.
     *
     * @return Collection<int, Invoice>
     */
    public function invoices(string $org): Collection
    {
        return Invoice::query()
            ->where('organization_id', $org)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get();
    }

    /**
     * A plan-change preview in the portal's JSON shape: the due-now and new-recurring amounts
     * (preformatted through the single money seam) plus the optional coupon promo block.
     *
     * @return array<string, mixed>
     */
    public function presentPreview(PlanChangePreview $preview, ?Coupon $coupon = null): array
    {
        $currency = $preview->newRecurring->currency();
        $dueNowMinor = $preview->dueNowQuote?->totals->gross->minor() ?? 0;

        return [
            'due_now_minor' => $dueNowMinor,
            // Preformatted server-side through the single money seam, so the client never
            // re-derives an amount with a hardcoded /100 + locale (wrong for JPY/ISK & co.).
            'due_now' => MoneyFormatter::minor($dueNowMinor, $currency),
            'new_recurring_minor' => $preview->newRecurring->minor(),
            'new_recurring' => MoneyFormatter::money($preview->newRecurring),
            'currency' => $currency,
            'effective_at' => $preview->effectiveAt->format(DateTimeImmutable::ATOM),
            'coupon' => $this->presentPreviewCoupon($preview, $coupon),
        ];
    }

    /**
     * The current-period usage-against-allowance for the org, filtered to the ENABLED metered
     * dimensions — the same {@see UsageReport} the console renders and the enforcement path
     * reads. Returns null for a flat/un-metered plan so the whole section is hidden.
     *
     * @return array<string, mixed>|null
     */
    public function usageMeters(Organization $organization): ?array
    {
        $report = $this->usage->forOrganization($organization);
        $meters = $report['meters'] ?? [];

        if (! is_array($meters)) {
            return null;
        }

        $metered = [];

        foreach ($meters as $meter) {
            if (is_array($meter) && ($meter['enabled'] ?? false)) {
                $metered[] = $meter;
            }
        }

        if ($metered === []) {
            return null;
        }

        return [
            'period_start' => is_string($report['period_start'] ?? null) ? $report['period_start'] : '',
            'period_end' => is_string($report['period_end'] ?? null) ? $report['period_end'] : '',
            'meters' => $metered,
        ];
    }

    /**
     * The seat breakdown in the portal's JSON/display shape.
     *
     * @return array<string, mixed>
     */
    public function presentSeats(Subscription $subscription): array
    {
        return $this->seatShape($this->seats->breakdown($subscription));
    }

    /**
     * The prorated seat-change preview: the amount due now on a buy (a reduction credits and
     * owes nothing now), server-preformatted through the single money seam.
     *
     * @return array<string, mixed>
     */
    public function presentSeatPreview(QuantityPreview $preview): array
    {
        $currency = $preview->charge->currency();
        // The tax-aware GROSS actually collected (preview == charge); zero on a credit.
        $dueNowMinor = $preview->grossDueNow->minor();

        return [
            'from_seats' => $preview->fromSeats,
            'to_seats' => $preview->toSeats,
            'is_credit' => $preview->isCredit(),
            'due_now_minor' => $dueNowMinor,
            'due_now' => MoneyFormatter::minor($dueNowMinor, $currency),
            // The signed NET proration (negative when a reduction credits the wallet).
            'charge' => MoneyFormatter::money($preview->charge),
            'currency' => $currency,
        ];
    }

    /**
     * The promo block on a plan-change preview: the recurring net after the coupon (through the
     * engine applier) — what renewals of the new plan will bill. Null when no code.
     *
     * @return array<string, mixed>|null
     */
    private function presentPreviewCoupon(PlanChangePreview $preview, ?Coupon $coupon): ?array
    {
        if (! $coupon instanceof Coupon) {
            return null;
        }

        $discount = $this->discounter->forCoupon($coupon, $preview->newRecurring, Carbon::now()->toDateTimeImmutable());
        $discounted = $discount === null ? $preview->newRecurring : $discount->discounted;

        return [
            'code' => $coupon->code,
            'duration' => $coupon->duration,
            'new_recurring_minor' => $discounted->minor(),
            'new_recurring' => MoneyFormatter::money($discounted),
            'discount_minor' => $discount === null ? 0 : $discount->amount->minor(),
        ];
    }

    /** @return array<string, mixed> */
    private function seatShape(SeatBreakdown $breakdown): array
    {
        return [
            'purchased' => $breakdown->purchased,
            'assigned' => $breakdown->assigned,
            'free' => $breakdown->free(),
            'full_count' => $breakdown->fullCount(),
            'light_count' => $breakdown->lightCount(),
            'full' => $breakdown->full,
            'light' => $breakdown->light,
            'assignable' => $breakdown->assignable,
        ];
    }
}
