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
     * @return array{period: array{start: string, end: string}, meters: array<string, array{used: int, allowance: int|null, overage: int}>}
     */
    public function forOrganization(string $org): array
    {
        [$start, $end] = $this->period($org);
        $fromMs = $this->toMillis($start);
        $toMs = $this->toMillis($end);

        $meters = [];

        foreach ($this->entitlements->forOrganization($org) as $meter => $policy) {
            $used = $this->eventLog->sum($org, $meter, $fromMs, $toMs);
            $allowance = $policy['allowance'];
            $overage = $allowance === null ? 0 : max(0, $used - $allowance);

            $meters[$meter] = [
                'used' => $used,
                'allowance' => $allowance,
                'overage' => $overage,
            ];
        }

        return [
            'period' => [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'meters' => $meters,
        ];
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
