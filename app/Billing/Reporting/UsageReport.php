<?php

declare(strict_types=1);

namespace App\Billing\Reporting;

use App\Billing\Metering\EntitlementsView;
use App\Billing\Metering\UsageSummaryView;
use App\Billing\Support\Initials;
use App\Models\Meter;
use App\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
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
     * The paginated Usage screen: organizations are paginated at the DATABASE and usage is
     * computed only for the visible page (PERF-1) — never the whole fleet up front. Each
     * rendered card composes the (now memoized, PERF-2) entitlement map with the reconciled
     * usage summary, so the cost is O(page × meters), not O(orgs × meters).
     *
     * @return LengthAwarePaginatorContract<int, array<string, mixed>>
     */
    public function paginate(?string $search = null, int $perPage = 8): LengthAwarePaginatorContract
    {
        $query = Organization::query()->orderBy('name');

        $search = $search !== null ? trim($search) : null;

        if ($search !== null && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return $query->paginate($perPage)
            ->through(fn (Organization $organization): array => $this->forOrganization($organization))
            ->withQueryString();
    }

    /**
     * The lightweight org list for the screen's chip selector — id + name only, one cheap
     * query, so the selector never drags the full per-org usage computation behind it.
     *
     * @return Collection<int, array{org_id: string, org: string}>
     */
    public function organizationChips(): Collection
    {
        return Organization::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Organization $organization): array => [
                'org_id' => $organization->id,
                'org' => $organization->name,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $summary = $this->usage->forOrganization($organization->id);
        $policies = $this->entitlements->forOrganization($organization->id);

        // One pass over the meter catalog for both the display name and unit (PERF-5).
        $names = [];
        $units = [];

        foreach (Meter::query()->get(['key', 'name', 'unit']) as $meter) {
            $names[$meter->key] = $meter->name;
            $units[$meter->key] = $meter->unit;
        }

        $meters = [];

        foreach ($summary['meters'] as $key => $meter) {
            $policy = $policies[$key] ?? ['enabled' => false, 'allowance' => null];
            $enabled = (bool) $policy['enabled'];
            $unlimited = $enabled && $policy['allowance'] === null;
            $allowance = $meter['allowance'];
            $used = $meter['used'];
            $overage = $meter['overage'];
            $projected = $meter['projected'];
            $projectedOverage = $meter['projected_overage'];

            $meters[] = [
                'key' => $key,
                'name' => $names[$key] ?? $key,
                'unit' => $units[$key] ?? '',
                'enabled' => $enabled,
                'unlimited' => $unlimited,
                'used' => $used,
                'allowance' => $allowance,
                'overage' => $overage,
                'projected' => $projected,
                'projected_overage' => $projectedOverage,
                'percent' => $this->percent($used, $allowance, $unlimited),
                'projected_percent' => $this->percent($projected, $allowance, $unlimited),
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
