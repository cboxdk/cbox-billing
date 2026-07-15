<?php

declare(strict_types=1);

namespace App\Billing;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Reporting\ChurnCalculator;
use Cbox\Billing\Reporting\MrrCalculator;
use Cbox\Billing\Reporting\ValueObjects\RevenueReport;

/**
 * Dashboard metrics for the current organization.
 *
 * KICKSTART SCOPE: the domain rows below are a synthetic-but-internally-consistent
 * demo dataset (182 active subscriptions across four plans). The RECURRING-REVENUE
 * figures are NOT hand-typed — they are computed by the real cboxdk/laravel-billing
 * engine ({@see MrrCalculator}, {@see ChurnCalculator}, {@see Money}). Swap the demo
 * dataset for the Subscription + Ledger read models to make this production-live.
 */
readonly class BillingMetrics
{
    private const CURRENCY = 'DKK';

    public function __construct(
        private MrrCalculator $mrr,
        private ChurnCalculator $churn,
    ) {}

    /** The plan mix behind the active book of business: [label, monthly minor units, count]. */
    private function activeBook(): array
    {
        return [
            ['plan' => 'Starter', 'monthly' => 29_000, 'count' => 96],
            ['plan' => 'Team', 'monthly' => 124_000, 'count' => 61],
            ['plan' => 'Business', 'monthly' => 349_000, 'count' => 21],
            ['plan' => 'Scale', 'monthly' => 990_000, 'count' => 4],
        ];
    }

    /** Feed every active subscription's monthly amount through the real MRR calculator. */
    public function revenue(): RevenueReport
    {
        return $this->mrr->summarize($this->monthlyAmounts());
    }

    /** @return iterable<Money> */
    private function monthlyAmounts(): iterable
    {
        foreach ($this->activeBook() as $tier) {
            for ($i = 0; $i < $tier['count']; $i++) {
                yield Money::ofMinor($tier['monthly'], self::CURRENCY);
            }
        }
    }

    /** Trailing-30-day logo churn, via the engine. */
    public function churnRate(): float
    {
        return $this->churn->rate(atStart: 185, churned: 3);
    }

    /** Sum of open (unpaid, issued) invoices. */
    public function outstanding(): Money
    {
        $total = Money::zero(self::CURRENCY);
        foreach ($this->invoices() as $invoice) {
            if ($invoice['status'] === 'open') {
                $total = $total->plus(Money::ofMinor($invoice['minor'], self::CURRENCY));
            }
        }

        return $total;
    }

    public function activeSubscriptions(): int
    {
        return array_sum(array_column($this->activeBook(), 'count'));
    }

    public function trials(): int
    {
        return 14;
    }

    /** Recent invoices for the dashboard + invoices screen. */
    public function invoices(): array
    {
        return [
            ['number' => 'CBX-2026-0412', 'org' => 'Hverdag ApS', 'ini' => 'HV', 'minor' => 124_000, 'status' => 'paid', 'date' => '2026-07-14'],
            ['number' => 'CBX-2026-0411', 'org' => 'Nordwind Media', 'ini' => 'NM', 'minor' => 349_000, 'status' => 'open', 'date' => '2026-07-14'],
            ['number' => 'CBX-2026-0410', 'org' => 'Klarhed A/S', 'ini' => 'KL', 'minor' => 29_000, 'status' => 'paid', 'date' => '2026-07-13'],
            ['number' => 'CBX-2026-0409', 'org' => 'Fjord Studio', 'ini' => 'FS', 'minor' => 990_000, 'status' => 'open', 'date' => '2026-07-13'],
            ['number' => 'CBX-2026-0408', 'org' => 'Meridian Labs', 'ini' => 'ML', 'minor' => 349_000, 'status' => 'paid', 'date' => '2026-07-12'],
            ['number' => 'CBX-2026-0407', 'org' => 'Vinter & Co', 'ini' => 'VC', 'minor' => 124_000, 'status' => 'draft', 'date' => '2026-07-12'],
            ['number' => 'CBX-2026-0406', 'org' => 'Aula Labs', 'ini' => 'AL', 'minor' => 29_000, 'status' => 'paid', 'date' => '2026-07-11'],
        ];
    }

    /** Active subscriptions for the subscriptions screen. */
    public function subscriptions(): array
    {
        return [
            ['org' => 'Hverdag ApS', 'ini' => 'HV', 'plan' => 'Team', 'minor' => 124_000, 'status' => 'active', 'started' => '2025-11-02', 'renews' => '2026-08-01'],
            ['org' => 'Nordwind Media', 'ini' => 'NM', 'plan' => 'Business', 'minor' => 349_000, 'status' => 'past_due', 'started' => '2025-08-19', 'renews' => '2026-07-19'],
            ['org' => 'Klarhed A/S', 'ini' => 'KL', 'plan' => 'Starter', 'minor' => 29_000, 'status' => 'active', 'started' => '2026-02-11', 'renews' => '2026-08-11'],
            ['org' => 'Fjord Studio', 'ini' => 'FS', 'plan' => 'Scale', 'minor' => 990_000, 'status' => 'active', 'started' => '2024-06-30', 'renews' => '2026-07-30'],
            ['org' => 'Aula Labs', 'ini' => 'AL', 'plan' => 'Starter', 'minor' => 29_000, 'status' => 'trialing', 'started' => '2026-07-08', 'renews' => '2026-07-22'],
            ['org' => 'Vinter & Co', 'ini' => 'VC', 'plan' => 'Team', 'minor' => 124_000, 'status' => 'active', 'started' => '2025-03-14', 'renews' => '2026-08-14'],
        ];
    }

    /** Format engine Money as a Danish-grouped amount, e.g. "DKK 216.370,00". */
    public static function format(Money $money): string
    {
        return $money->currency() . ' ' . number_format($money->minor() / 100, 2, ',', '.');
    }

    /** Format bare minor units in a currency. */
    public static function formatMinor(int $minor, string $currency = self::CURRENCY): string
    {
        return self::format(Money::ofMinor($minor, $currency));
    }
}
