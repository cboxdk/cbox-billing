<?php

declare(strict_types=1);

namespace App\Billing\Notifications;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Reporting\UsageReport;
use App\Mail\UsageAlertMail;
use App\Models\Organization;
use App\Models\UsageAlertDispatch;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Emits the optional usage/overage alert (feature gap #2): for an org it reads the SAME
 * {@see UsageReport} the console and portal render, and for each enabled, limited (non-unlimited)
 * meter whose current-period usage has crossed a configured threshold of its included allowance
 * (default 80% / 100%), it queues a branded, localized {@see UsageAlertMail} through the
 * {@see NotifiesCustomers} seam — which already honours the optional-notification opt-out and the
 * test-mode capture.
 *
 * Idempotent per (org, meter, billing period, threshold) via the {@see UsageAlertDispatch} ledger:
 * a crossing fires exactly once per period even though the sweep runs many times a day. When a run
 * finds several thresholds already crossed, it emails only the HIGHEST newly-crossed one (no double
 * email) and records the lower crossed thresholds as satisfied so no straggler fires later.
 */
readonly class UsageAlertEmitter
{
    public function __construct(
        private UsageReport $usage,
        private NotifiesCustomers $notifier,
        private Config $config,
    ) {}

    /**
     * Evaluate `$organization`'s metered usage and queue any newly-crossed threshold alerts.
     * Returns the number of alerts queued.
     */
    public function forOrganization(Organization $organization): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $thresholds = $this->thresholds();

        if ($thresholds === []) {
            return 0;
        }

        $report = $this->usage->forOrganization($organization);
        $meters = is_array($report['meters'] ?? null) ? $report['meters'] : [];
        $periodKey = is_string($report['period_start'] ?? null) ? $report['period_start'] : '';
        $periodEndLabel = is_string($report['period_end'] ?? null) ? $report['period_end'] : '';

        $fired = 0;

        foreach ($meters as $meter) {
            if (! is_array($meter)) {
                continue;
            }

            // Only an enabled, limited meter has a threshold to cross — an unlimited or
            // deny-by-default meter is skipped.
            $allowance = $meter['allowance'] ?? null;

            if (($meter['enabled'] ?? false) !== true || ($meter['unlimited'] ?? false) === true || ! is_int($allowance) || $allowance <= 0) {
                continue;
            }

            $percent = is_int($meter['percent'] ?? null) ? $meter['percent'] : 0;

            $crossed = array_values(array_filter($thresholds, static fn (int $threshold): bool => $percent >= $threshold));

            if ($crossed === []) {
                continue;
            }

            $highest = (int) end($crossed);
            $meterKey = is_string($meter['key'] ?? null) ? $meter['key'] : '';
            $newlyCrossed = $this->recordOnce($organization->id, $meterKey, $periodKey, $highest);

            // Mark the lower crossed thresholds satisfied too, so a later run never emails a stale
            // 80% after a 100% jump.
            foreach ($crossed as $threshold) {
                if ((int) $threshold !== $highest) {
                    $this->recordOnce($organization->id, $meterKey, $periodKey, (int) $threshold);
                }
            }

            if (! $newlyCrossed) {
                continue;
            }

            $used = is_int($meter['used'] ?? null) ? $meter['used'] : 0;
            $unit = is_string($meter['unit'] ?? null) ? $meter['unit'] : '';
            $meterName = is_string($meter['name'] ?? null) ? $meter['name'] : $meterKey;

            $this->notifier->usageAlert(
                $organization,
                $meterName,
                $highest,
                $percent,
                $this->formatUnits($used, $unit),
                $this->formatUnits($allowance, $unit),
                $periodEndLabel,
            );

            $fired++;
        }

        return $fired;
    }

    /**
     * Record that the alert for (org, meter, period, threshold) has fired. Returns true when this
     * is the first time (a new row); false when it was already recorded — the unique key is the
     * concurrency-safe guard, so two concurrent sweeps still email exactly once.
     */
    private function recordOnce(string $organizationId, string $meterKey, string $periodKey, int $threshold): bool
    {
        // No resolvable billing period → no stable idempotency key, so do not record (and do not
        // email): an empty period_key would collapse distinct periods onto one ledger row.
        if ($periodKey === '' || $meterKey === '') {
            return false;
        }

        try {
            UsageAlertDispatch::query()->create([
                'organization_id' => $organizationId,
                'meter_key' => $meterKey,
                'period_key' => $periodKey,
                'threshold' => $threshold,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // The unique key rejected the duplicate — already dispatched this period. Only a
            // uniqueness violation is swallowed; any other query failure (a real DB fault)
            // propagates rather than being silently treated as "already sent".
            return false;
        }
    }

    /**
     * The configured included-allowance thresholds (percent), sanitized, deduped, ascending.
     *
     * @return list<int>
     */
    private function thresholds(): array
    {
        $configured = $this->config->get('billing.usage_alerts.thresholds', [80, 100]);
        $thresholds = [];

        foreach (is_array($configured) ? $configured : [] as $value) {
            if (is_numeric($value)) {
                $threshold = (int) $value;

                if ($threshold >= 1 && $threshold <= 100) {
                    $thresholds[$threshold] = $threshold;
                }
            }
        }

        $thresholds = array_values($thresholds);
        sort($thresholds);

        return $thresholds;
    }

    private function enabled(): bool
    {
        return $this->config->get('billing.usage_alerts.enabled', true) !== false;
    }

    /** A used/allowance count for display, e.g. "8,400 requests". */
    private function formatUnits(int $count, string $unit): string
    {
        $formatted = number_format($count);

        return $unit !== '' ? $formatted.' '.$unit : $formatted;
    }
}
