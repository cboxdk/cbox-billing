<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Invoicing\Enums\InvoiceStatus;
use App\Billing\Support\Initials;
use App\Billing\Support\MoneyFormatter;
use App\Billing\Support\SubscriptionRevenue;
use App\Billing\Support\SubscriptionStanding;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Contracts\BillingCurrencyLock;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Read model for the Customers screens. Projects real {@see Organization} rows with their
 * active subscription, the engine's durable {@see AccountStanding} (good / disputed /
 * suspended), and their outstanding balance into the customer table + detail shapes.
 */
readonly class CustomerReport
{
    public function __construct(
        private AccountStanding $standing,
        private BillingCurrencyLock $currencyLock,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        return Organization::query()
            ->with(['subscriptions.plan.prices', 'subscriptions.organization', 'invoices'])
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization): array => $this->row($organization));
    }

    /**
     * The paginated, optionally searched list for the Customers screen. Search matches the
     * organization name, id, or billing country.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Organization::query()
            ->with(['subscriptions.plan.prices', 'subscriptions.organization', 'invoices'])
            ->orderBy('name');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $query->where(function ($sub) use ($search): void {
                $sub->where('name', 'like', '%'.$search.'%')
                    ->orWhere('id', 'like', '%'.$search.'%')
                    ->orWhere('billing_country', 'like', '%'.$search.'%');
            });
        }

        return $query->paginate($perPage)
            ->through(fn (Organization $organization): array => $this->row($organization))
            ->withQueryString();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $organization = Organization::query()
            ->with(['subscriptions.plan.prices', 'subscriptions.organization', 'invoices'])
            ->find($id);

        if ($organization === null) {
            return null;
        }

        $row = $this->row($organization);

        $row['billing_email'] = $organization->billing_email;
        $row['billing_country'] = $organization->billing_country;
        $row['tax_id'] = $organization->tax_id;
        $row['suspended'] = $organization->isSuspended();
        $row['suspended_at'] = $organization->suspended_at?->format('Y-m-d H:i');
        // The billing currency is one-way locked once the account transacts — a finalized
        // invoice stamps the engine lock. Either signal freezes the console's currency field.
        $row['currency_locked'] = $this->currencyLock->lockedCurrency($organization->id) !== null
            || $organization->invoices->isNotEmpty();
        $row['invoices'] = $organization->invoices
            ->sortByDesc('issued_at')
            ->map(static fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'status' => $invoice->status->value,
                'date' => $invoice->issued_at?->format('Y-m-d') ?? '—',
            ])->values()->all();

        return $row;
    }

    /**
     * The coupons this organization has redeemed (newest first), each cross-linking to the
     * authored coupon and the subscription it was applied to.
     *
     * @return list<array{coupon_id: int, code: string, name: string, subscription_id: int|null, redeemed_at: string}>
     */
    public function redemptions(string $organizationId): array
    {
        return array_values(CouponRedemption::query()
            ->with('coupon')
            ->where('organization_id', $organizationId)
            ->orderByDesc('redeemed_at')
            ->get()
            ->map(static function (CouponRedemption $redemption): array {
                $coupon = $redemption->coupon;

                return [
                    'coupon_id' => $redemption->coupon_id,
                    'code' => $coupon instanceof Coupon ? $coupon->code : '—',
                    'name' => $coupon instanceof Coupon ? ($coupon->name ?? '—') : '—',
                    'subscription_id' => $redemption->subscription_id,
                    'redeemed_at' => $redemption->redeemed_at->format('Y-m-d'),
                ];
            })
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Organization $organization): array
    {
        $subscription = $organization->subscriptions
            ->firstWhere('status', SubscriptionStatus::Active);

        $currency = $organization->billing_currency ?? 'DKK';
        $outstanding = Money::zero($currency);

        foreach ($organization->invoices as $invoice) {
            if ($invoice->status === InvoiceStatus::Open && $invoice->currency === $currency) {
                $outstanding = $outstanding->plus($invoice->total());
            }
        }

        return [
            'id' => $organization->id,
            'org' => $organization->name,
            'ini' => Initials::of($organization->name),
            'currency' => $currency,
            'country' => $organization->billing_country ?? '—',
            'plan' => $subscription instanceof Subscription ? $subscription->plan?->name : null,
            // The subscription the plan/status panel describes, so the detail page can link to it.
            'subscription_id' => $subscription instanceof Subscription ? $subscription->id : null,
            'status' => $subscription instanceof Subscription
                ? SubscriptionStanding::of($subscription)
                : 'none',
            'mrr' => $subscription instanceof Subscription
                ? SubscriptionRevenue::monthly($subscription)->minor()
                : 0,
            'outstanding' => $outstanding->minor(),
            'outstanding_label' => MoneyFormatter::money($outstanding),
            'standing' => $this->standing->standingOf($organization->id)->value,
        ];
    }
}
