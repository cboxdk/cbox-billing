<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Reporting\RevenueAnalytics;
use Cbox\Billing\Reporting\ValueObjects\MrrLine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The revenue-analytics screens. Each action resolves the {@see RevenueAnalytics} read
 * model, picks the window and display currency, and hands the engine-computed figures to
 * the view — no arithmetic lives here. The window is the trailing month (month-over-month
 * movement); the cohort axis is the trailing six months.
 */
class AnalyticsController extends Controller
{
    public function revenue(Request $request, RevenueAnalytics $analytics): View
    {
        [$start, $end] = $this->window();
        $revenue = $analytics->revenue();
        $currency = $this->currency($request, $revenue->lines);

        $movement = $analytics->movement($start, $end);
        $waterfall = $movement->waterfallFor($currency);

        return view('analytics.revenue', [
            'activeArea' => 'analytics',
            'activeNav' => 'revenue',
            'currency' => $currency,
            'currencies' => $this->currencies($revenue->lines),
            'line' => $revenue->lineFor($currency),
            'waterfall' => $waterfall,
            'arr' => $waterfall?->toArr(),
            'windowStart' => $start->format('Y-m-d'),
            'windowEnd' => $end->format('Y-m-d'),
        ]);
    }

    public function retention(Request $request, RevenueAnalytics $analytics): View
    {
        [$start, $end] = $this->window();
        $revenue = $analytics->revenue();
        $currency = $this->currency($request, $revenue->lines);

        $periods = $analytics->monthLabels(6, $end);

        return view('analytics.retention', [
            'activeArea' => 'analytics',
            'activeNav' => 'retention',
            'currency' => $currency,
            'currencies' => $this->currencies($revenue->lines),
            'rates' => $analytics->retention($start, $end, $currency),
            'waterfall' => $analytics->movement($start, $end)->waterfallFor($currency),
            'churnRate' => $analytics->customerChurn($start, $end),
            'cohorts' => $analytics->cohorts($periods),
            'windowStart' => $start->format('Y-m-d'),
            'windowEnd' => $end->format('Y-m-d'),
        ]);
    }

    /**
     * The trailing-month window: [one month ago, now]. Month-over-month is the natural
     * frame for an MRR-movement bridge.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(): array
    {
        $end = Carbon::now();

        return [$end->copy()->subMonthsNoOverflow(1), $end];
    }

    /**
     * The requested `?currency=` when the book carries it, else the primary currency (else
     * the first currency present).
     *
     * @param  list<MrrLine>  $lines
     */
    private function currency(Request $request, array $lines): string
    {
        $available = $this->currencies($lines);
        $requested = $request->query('currency');

        if (is_string($requested) && in_array(strtoupper($requested), $available, true)) {
            return strtoupper($requested);
        }

        $primary = config('billing.default_currency');
        $primary = is_string($primary) ? $primary : 'DKK';

        if (in_array($primary, $available, true)) {
            return $primary;
        }

        return $available[0] ?? $primary;
    }

    /**
     * The currencies the book has recurring revenue in.
     *
     * @param  list<MrrLine>  $lines
     * @return list<string>
     */
    private function currencies(array $lines): array
    {
        return array_map(static fn (MrrLine $line): string => $line->currency, $lines);
    }
}
