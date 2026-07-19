<?php

declare(strict_types=1);

namespace App\Billing\TestMode;

use App\Billing\Support\MoneyFormatter;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\TestClock;
use Illuminate\Support\Collection;

/**
 * The read model behind the console's test-clock pages. Thin projections of a clock, the
 * subscriptions bound to it, and the invoices those subscriptions have accrued — so an
 * integrator can read back the state a clock advance produced. Every read runs in the test
 * plane (the caller sets the mode), so only sandbox rows surface.
 */
readonly class TestClockReport
{
    /** @return array<int, array<string, mixed>> */
    public function clocks(): array
    {
        return TestClock::query()
            ->withCount('subscriptions')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TestClock $clock): array => [
                'id' => $clock->id,
                'name' => $clock->name,
                'now_at' => $clock->now_at->format('Y-m-d H:i'),
                'charge_outcome' => $clock->charge_outcome,
                'subscriptions' => $clock->subscriptions_count,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function detail(TestClock $clock): array
    {
        $subscriptions = Subscription::query()
            ->where('test_clock_id', $clock->id)
            ->with(['organization', 'plan'])
            ->orderBy('id')
            ->get()
            ->map(fn (Subscription $subscription): array => [
                'id' => $subscription->id,
                'organization' => $subscription->organization !== null ? $subscription->organization->name : $subscription->organization_id,
                'plan' => $subscription->plan !== null ? $subscription->plan->name : '—',
                'status' => $subscription->standing(),
                'period_end' => $subscription->current_period_end?->format('Y-m-d') ?? '—',
                'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d') ?? null,
            ]);

        $subscriptionIds = Subscription::query()->where('test_clock_id', $clock->id)->pluck('id')->all();

        $invoices = Invoice::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => MoneyFormatter::money($invoice->total()),
                'paid' => $invoice->isPaid(),
                'period' => $invoice->period_start?->format('Y-m-d').' – '.$invoice->period_end?->format('Y-m-d'),
                'issued' => $invoice->issued_at?->format('Y-m-d') ?? '—',
            ]);

        return [
            'clock' => [
                'id' => $clock->id,
                'name' => $clock->name,
                'now_at' => $clock->now_at->format('Y-m-d H:i'),
                'charge_outcome' => $clock->charge_outcome,
            ],
            'subscriptions' => $subscriptions,
            'invoices' => $invoices,
        ];
    }

    /**
     * Test subscriptions not yet bound to any clock — the candidates the console offers to bind.
     *
     * @return Collection<int, Subscription>
     */
    public function bindableSubscriptions(): Collection
    {
        return Subscription::query()
            ->whereNull('test_clock_id')
            ->with(['organization', 'plan'])
            ->orderBy('id')
            ->get();
    }
}
