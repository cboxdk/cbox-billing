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
use Illuminate\Support\Carbon;

/**
 * The dashboard's recurring-revenue read model, computed from real rows: every active
 * {@see Subscription}'s monthly-equivalent amount is fed through the engine's
 * {@see MrrCalculator} (MRR/ARR per currency), churn through the {@see ChurnCalculator},
 * and outstanding is the live sum of open {@see Invoice}s. No figure is hand-typed.
 */
readonly class RevenueMetrics
{
    public function __construct(
        private MrrCalculator $mrr,
        private ChurnCalculator $churn,
    ) {}

    /** MRR/ARR per currency, summed by the engine over active subscriptions. */
    public function revenue(): RevenueReport
    {
        return $this->mrr->summarize($this->monthlyAmounts());
    }

    public function primaryCurrency(): string
    {
        $default = config('billing.default_currency');

        return is_string($default) ? $default : 'DKK';
    }

    /** @return iterable<Money> */
    private function monthlyAmounts(): iterable
    {
        $subscriptions = Subscription::query()
            ->where('status', 'active')
            ->with(['organization', 'plan.prices'])
            ->cursor();

        foreach ($subscriptions as $subscription) {
            yield SubscriptionRevenue::monthly($subscription);
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
     * Active book of business broken down by plan — count and summed monthly amount per
     * plan, in the primary currency. Feeds the dashboard's revenue-by-plan panel.
     *
     * @return list<array{plan: string, count: int, minor: int, currency: string}>
     */
    public function planBreakdown(): array
    {
        $currency = $this->primaryCurrency();
        $breakdown = [];

        $subscriptions = Subscription::query()
            ->where('status', 'active')
            ->with(['organization', 'plan.prices'])
            ->get();

        foreach ($subscriptions as $subscription) {
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
