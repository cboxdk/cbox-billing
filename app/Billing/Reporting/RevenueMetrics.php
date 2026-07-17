<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Support\SubscriptionRevenue;
use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ChurnCalculator;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Reporting\ValueObjects\RevenueReport;
use Cbox\Billing\Reporting\ValueObjects\SubscriptionMrr;
use Illuminate\Support\Carbon;

/**
 * The dashboard's recurring-revenue read model, computed from real rows: every serving
 * {@see Subscription}'s monthly-equivalent amount is fed — with its lifecycle status —
 * through the engine's {@see MrrCalculator} (MRR/ARR per currency), which applies the
 * state→MRR policy so a past-due or non-renewing subscription is counted while a trialing,
 * paused or canceled one is not. Churn runs through the {@see ChurnCalculator}, and
 * outstanding is the live sum of open {@see Invoice}s. No figure is hand-typed.
 */
readonly class RevenueMetrics
{
    public function __construct(
        private MrrCalculator $mrr,
        private ChurnCalculator $churn,
    ) {}

    /** MRR/ARR per currency, summed by the engine over serving subscriptions under its state→MRR policy. */
    public function revenue(): RevenueReport
    {
        return $this->mrr->summarizeSubscriptions($this->subscriptionMrrs());
    }

    public function primaryCurrency(): string
    {
        $default = config('billing.default_currency');

        return is_string($default) ? $default : 'DKK';
    }

    /**
     * Every serving subscription paired with its status, so the engine (not a hand-written
     * where clause) decides which lifecycle states contribute to MRR.
     *
     * @return iterable<SubscriptionMrr>
     */
    private function subscriptionMrrs(): iterable
    {
        $subscriptions = Subscription::query()
            ->serving()
            ->with(['organization', 'plan.prices.tiers'])
            ->cursor();

        foreach ($subscriptions as $subscription) {
            yield new SubscriptionMrr($subscription->status, SubscriptionRevenue::monthly($subscription));
        }
    }

    /** Trailing-30-day logo churn, via the engine calculator. */
    public function churnRate(): float
    {
        $windowStart = Carbon::now()->subDays(30);

        $atStart = Subscription::query()
            ->where('created_at', '<', $windowStart)
            ->count();

        $churned = Subscription::query()
            ->where('status', 'canceled')
            ->where('updated_at', '>=', $windowStart)
            ->count();

        return $this->churn->rate($atStart, $churned);
    }

    /** Sum of open invoices in the primary currency. */
    public function outstanding(): Money
    {
        $currency = $this->primaryCurrency();
        $total = Money::zero($currency);

        $open = Invoice::query()
            ->where('status', 'open')
            ->where('currency', $currency)
            ->get();

        foreach ($open as $invoice) {
            $total = $total->plus($invoice->total());
        }

        return $total;
    }

    public function openInvoiceCount(): int
    {
        return Invoice::query()->where('status', 'open')->count();
    }

    /**
     * The billing book of business broken down by plan — count and summed monthly amount
     * per plan, in the primary currency. Feeds the dashboard's revenue-by-plan panel and
     * is kept consistent with the MRR tile: the serving subscriptions that actually
     * contribute to MRR (the engine's state→MRR policy), so past-due and non-renewing
     * accounts appear while trialing, paused and canceled ones do not.
     *
     * @return list<array{plan: string, count: int, minor: int, currency: string}>
     */
    public function planBreakdown(): array
    {
        $currency = $this->primaryCurrency();
        $breakdown = [];

        $subscriptions = Subscription::query()
            ->serving()
            ->with(['organization', 'plan.prices.tiers'])
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! $this->mrr->contributes($subscription->status)) {
                continue;
            }

            $monthly = SubscriptionRevenue::monthly($subscription);

            if ($monthly->currency() !== $currency) {
                continue;
            }

            $plan = $subscription->plan;
            $name = $plan !== null ? $plan->name : '—';
            $breakdown[$name] ??= ['plan' => $name, 'count' => 0, 'minor' => 0, 'currency' => $currency];
            $breakdown[$name]['count']++;
            $breakdown[$name]['minor'] += $monthly->minor();
        }

        $rows = array_values($breakdown);
        usort($rows, static fn (array $a, array $b): int => $b['minor'] <=> $a['minor']);

        return $rows;
    }
}
