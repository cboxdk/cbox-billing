<?php

declare(strict_types=1);

namespace App\Billing\Metering;

use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Billing\Metering\Contracts\EventLog;
use Illuminate\Support\Carbon;

/**
 * The org's usage-against-allowance summary for the current billing period — the
 * `/usage/{org}` payload. Composes the resolved per-meter allowances (through the same
 * {@see EntitlementsView} the enforcer reads) with the durable usage totals from the
 * immutable {@see EventLog} (the metering source of truth), so `used` is the reconciled
 * truth rather than a hot-path counter. The period is the org's active-subscription
 * window, falling back to the calendar month when it has no subscription.
 */
readonly class UsageSummaryView
{
    public function __construct(
        private EntitlementsView $entitlements,
        private EventLog $eventLog,
    ) {}

    /**
     * The per-meter breakdown for the current period (#55): `used` reconciled from the
     * durable event log, the `allowance` included by the plan, the `overage` already run
     * past it, and a straight-line `projected` end-of-period figure (with its own
     * `projected_overage`) extrapolated from how far through the period we are. A meter with
     * an unlimited (null) allowance carries no overage.
     *
     * @return array{
     *     period: array{start: string, end: string, elapsed_fraction: float},
     *     meters: array<string, array{used: int, allowance: int|null, overage: int, projected: int, projected_overage: int}>
     * }
     */
    public function forOrganization(string $org): array
    {
        [$start, $end] = $this->period($org);
        $fromMs = $this->toMillis($start);
        $toMs = $this->toMillis($end);
        $fraction = $this->elapsedFraction($start, $end);

        $meters = [];

        foreach ($this->entitlements->forOrganization($org) as $meter => $policy) {
            $used = $this->eventLog->sum($org, $meter, $fromMs, $toMs);
            $allowance = $policy['allowance'];
            $overage = $allowance === null ? 0 : max(0, $used - $allowance);
            $projected = $this->project($used, $fraction);
            $projectedOverage = $allowance === null ? 0 : max(0, $projected - $allowance);

            $meters[$meter] = [
                'used' => $used,
                'allowance' => $allowance,
                'overage' => $overage,
                'projected' => $projected,
                'projected_overage' => $projectedOverage,
            ];
        }

        return [
            'period' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'elapsed_fraction' => round($fraction, 4),
            ],
            'meters' => $meters,
        ];
    }

    /**
     * How far through the billing period "now" is, in `[0, 1]`. A period not yet started
     * reads 0; a period fully elapsed reads 1, so a closed period projects to exactly its
     * observed usage.
     */
    private function elapsedFraction(Carbon $start, Carbon $end): float
    {
        $now = Carbon::now();
        $total = $end->getTimestamp() - $start->getTimestamp();

        if ($total <= 0 || $now->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        if ($now->greaterThanOrEqualTo($end)) {
            return 1.0;
        }

        return ($now->getTimestamp() - $start->getTimestamp()) / $total;
    }

    /**
     * Straight-line extrapolation of `$used` to the end of the period. Before any of the
     * period has elapsed (fraction 0) there is nothing to extrapolate from, so the observed
     * usage stands as the projection.
     */
    private function project(int $used, float $fraction): int
    {
        if ($fraction <= 0.0 || $used === 0) {
            return $used;
        }

        return (int) ceil($used / $fraction);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(string $org): array
    {
        $subscription = Organization::query()->find($org)?->activeSubscription();

        if ($subscription instanceof Subscription) {
            return [
                $subscription->current_period_start ?? Carbon::now()->startOfMonth(),
                $subscription->current_period_end ?? Carbon::now()->endOfMonth(),
            ];
        }

        return [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()];
    }

    private function toMillis(Carbon $carbon): int
    {
        return (int) ($carbon->getTimestamp() * 1000);
    }
}
