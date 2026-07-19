<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Mode\BillingContext;
use App\Billing\Payments\Dunning\DeclineCategory;
use App\Models\PaymentRetry;
use Cbox\Billing\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The recovery read model for adaptive dunning — how well failed charges are being recovered,
 * the payoff of the whole feature. Every figure is computed over real {@see PaymentRetry} rows,
 * confined to the request's current plane (live/test) so the console's sandbox and live figures
 * never mix. Every figure:
 *
 *  - RECOVERY RATE — recovered ÷ entered-dunning (a `recovered` row is a charge the schedule
 *    won back; entered is every row that ever entered dunning).
 *  - BY CATEGORY — the same rate sliced by decline category, so an operator sees which declines
 *    recover and which are lost causes.
 *  - AVERAGE ATTEMPTS-TO-RECOVER — how many retries a recovery typically takes (the curve's
 *    efficiency).
 *  - REVENUE RECOVERED — the money the recovered charges brought back, per currency.
 *  - INVOLUNTARY CHURN AVERTED — the recovered subscriptions that would otherwise have churned
 *    for non-payment (the count the recovery saved), against the involuntary churn that still
 *    happened (exhausted schedules).
 *
 * Pure read model — no writes, no side effects.
 */
readonly class RecoveryAnalytics
{
    public function __construct(private BillingContext $context) {}

    public function entered(): int
    {
        return PaymentRetry::query()->count();
    }

    public function recovered(): int
    {
        return PaymentRetry::query()->where('status', PaymentRetry::STATUS_RECOVERED)->count();
    }

    public function exhausted(): int
    {
        return PaymentRetry::query()->where('status', PaymentRetry::STATUS_EXHAUSTED)->count();
    }

    /** Currently working through a schedule. */
    public function active(): int
    {
        return PaymentRetry::query()->where('status', PaymentRetry::STATUS_RETRYING)->count();
    }

    /** Recovered ÷ entered-dunning, in [0,1]. Zero when nothing has entered dunning. */
    public function recoveryRate(): float
    {
        $entered = $this->entered();

        // Float division (PHP returns int for an evenly-divisible int/int) so the rate is always
        // a float in [0,1].
        return $entered === 0 ? 0.0 : (float) $this->recovered() / $entered;
    }

    /**
     * The mean number of fired attempts a recovery took (recovered rows only). Zero when nothing
     * has recovered.
     */
    public function averageAttemptsToRecover(): float
    {
        $avg = PaymentRetry::query()
            ->where('status', PaymentRetry::STATUS_RECOVERED)
            ->avg('attempts');

        return is_numeric($avg) ? round((float) $avg, 2) : 0.0;
    }

    /**
     * Revenue won back by recovered charges, as Money per currency (the recovered invoices'
     * totals). Keyed by ISO currency.
     *
     * @return array<string, Money>
     */
    public function recoveredRevenue(): array
    {
        $rows = DB::table('payment_retries')
            ->join('invoices', 'invoices.id', '=', 'payment_retries.invoice_id')
            ->where('payment_retries.status', PaymentRetry::STATUS_RECOVERED)
            ->where('payment_retries.livemode', $this->context->livemode())
            ->groupBy('invoices.currency')
            ->selectRaw('invoices.currency as currency, SUM(invoices.total_minor) as minor')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $currency = is_string($row->currency) ? $row->currency : 'DKK';
            $minor = is_numeric($row->minor) ? (int) $row->minor : 0;
            $out[$currency] = Money::ofMinor($minor, $currency);
        }

        return $out;
    }

    /** Recovered subscriptions the schedule saved from involuntary (non-payment) churn. */
    public function involuntaryChurnAverted(): int
    {
        return $this->recovered();
    }

    /** Involuntary churn that still happened — schedules that exhausted without recovering. */
    public function involuntaryChurn(): int
    {
        return $this->exhausted();
    }

    /**
     * The recovery rate sliced by decline category — entered, recovered, and the rate for each
     * category that has seen any dunning, ordered by volume.
     *
     * @return list<array{category: string, label: string, pill: string, entered: int, recovered: int, rate: float}>
     */
    public function byCategory(): array
    {
        /** @var array<string, array{entered: int, recovered: int}> $tallies */
        $tallies = [];

        $rows = DB::table('payment_retries')
            ->where('livemode', $this->context->livemode())
            ->groupBy('decline_category', 'status')
            ->selectRaw('decline_category, status, COUNT(*) as total')
            ->get();

        foreach ($rows as $row) {
            $category = is_string($row->decline_category) && $row->decline_category !== ''
                ? $row->decline_category
                : DeclineCategory::Unknown->value;
            $count = is_numeric($row->total) ? (int) $row->total : 0;

            $tallies[$category] ??= ['entered' => 0, 'recovered' => 0];
            $tallies[$category]['entered'] += $count;

            if ($row->status === PaymentRetry::STATUS_RECOVERED) {
                $tallies[$category]['recovered'] += $count;
            }
        }

        $out = [];

        foreach ($tallies as $categoryValue => $tally) {
            $category = DeclineCategory::tryFrom($categoryValue) ?? DeclineCategory::Unknown;
            $out[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'pill' => $category->pill(),
                'entered' => $tally['entered'],
                'recovered' => $tally['recovered'],
                'rate' => $tally['entered'] === 0 ? 0.0 : (float) $tally['recovered'] / $tally['entered'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['entered'] <=> $a['entered']);

        return $out;
    }

    /**
     * A flat summary bag for the dashboard tile / console strip.
     *
     * @return array{entered: int, recovered: int, exhausted: int, active: int, rate: float, averted: int, avg_attempts: float, revenue: array<string, Money>}
     */
    public function summary(): array
    {
        return [
            'entered' => $this->entered(),
            'recovered' => $this->recovered(),
            'exhausted' => $this->exhausted(),
            'active' => $this->active(),
            'rate' => $this->recoveryRate(),
            'averted' => $this->involuntaryChurnAverted(),
            'avg_attempts' => $this->averageAttemptsToRecover(),
            'revenue' => $this->recoveredRevenue(),
        ];
    }
}
