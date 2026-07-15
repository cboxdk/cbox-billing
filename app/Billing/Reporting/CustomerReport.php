<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\Initials;
use App\Billing\Support\MoneyFormatter;
use App\Billing\Support\SubscriptionRevenue;
use App\Billing\Support\SubscriptionStanding;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Support\Collection;

/**
 * Read model for the Customers screens. Projects real {@see Organization} rows with their
 * active subscription, the engine's durable {@see AccountStanding} (good / disputed /
 * suspended), and their outstanding balance into the customer table + detail shapes.
 */
readonly class CustomerReport
{
    public function __construct(private AccountStanding $standing) {}

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
        $row['invoices'] = $organization->invoices
            ->sortByDesc('issued_at')
            ->map(static fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'date' => $invoice->issued_at?->format('Y-m-d') ?? '—',
            ])->values()->all();

        return $row;
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
            if ($invoice->status === 'open' && $invoice->currency === $currency) {
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
