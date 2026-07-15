<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Metering\EntitlementsView;
use App\Billing\Metering\UsageSummaryView;
use App\Billing\Support\Initials;
use App\Models\Meter;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Read model for the Usage screen. Composes the {@see EntitlementsView} (the resolved
 * per-meter policy: enabled / unlimited / allowance) with the {@see UsageSummaryView}
 * (used vs. overage, reconciled from the immutable event log) into per-org, per-meter
 * bars. Deny-by-default surfaces honestly: a disabled meter is shown disabled rather
 * than as an empty bar.
 */
readonly class UsageReport
{
    public function __construct(
        private EntitlementsView $entitlements,
        private UsageSummaryView $usage,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forAllOrganizations(): Collection
    {
        return Organization::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization): array => $this->forOrganization($organization));
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $summary = $this->usage->forOrganization($organization->id);
        $policies = $this->entitlements->forOrganization($organization->id);
        $names = Meter::query()->pluck('name', 'key')->all();
        $units = Meter::query()->pluck('unit', 'key')->all();

        $meters = [];

        foreach ($summary['meters'] as $key => $meter) {
            $policy = $policies[$key] ?? ['enabled' => false, 'allowance' => null];
            $enabled = (bool) $policy['enabled'];
            $unlimited = $enabled && $policy['allowance'] === null;
            $allowance = $meter['allowance'];
            $used = $meter['used'];
            $overage = $meter['overage'];

            $meters[] = [
                'key' => $key,
                'name' => $names[$key] ?? $key,
                'unit' => $units[$key] ?? '',
                'enabled' => $enabled,
                'unlimited' => $unlimited,
                'used' => $used,
                'allowance' => $allowance,
                'overage' => $overage,
                'percent' => $this->percent($used, $allowance, $unlimited),
                'state' => $this->state($enabled, $unlimited, $used, $allowance, $overage),
            ];
        }

        return [
            'org' => $organization->name,
            'org_id' => $organization->id,
            'ini' => Initials::of($organization->name),
            'period_start' => substr((string) $summary['period']['start'], 0, 10),
            'period_end' => substr((string) $summary['period']['end'], 0, 10),
            'meters' => $meters,
        ];
    }

    private function percent(int $used, ?int $allowance, bool $unlimited): int
    {
        if ($unlimited) {
            return $used > 0 ? 100 : 6;
        }

        if ($allowance === null || $allowance <= 0) {
            return 0;
        }

        return (int) min(100, round(($used / $allowance) * 100));
    }

    private function state(bool $enabled, bool $unlimited, int $used, ?int $allowance, int $overage): string
    {
        if (! $enabled) {
            return 'disabled';
        }

        if ($overage > 0) {
            return 'over';
        }

        if ($unlimited) {
            return 'unlimited';
        }

        if ($allowance !== null && $allowance > 0 && $used / $allowance >= 0.8) {
            return 'warn';
        }

        return 'ok';
    }
}
