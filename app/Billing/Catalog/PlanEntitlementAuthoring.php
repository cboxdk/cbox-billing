<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Seams\SubscriptionMeterPolicyResolver;
use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;

/**
 * Create / edit / delete a plan's metered {@see PlanEntitlement} — the included allowance,
 * per-unit overage weight and overage behaviour for one meter, the durable source the
 * {@see SubscriptionMeterPolicyResolver} projects into an engine
 * {@see MeterPolicy}. Each entitlement is one
 * `(plan, meter)` bucket, so at most one row per meter per plan. Deleting an entitlement
 * reverts that meter to deny-by-default for the plan — safe, so it is a plain hard-delete.
 */
readonly class PlanEntitlementAuthoring
{
    /**
     * @param  array{meter_id: int, enabled: bool, unlimited: bool, allowance: int, multiplier: ?float, overage: OverageBehaviour}  $data
     */
    public function create(Plan $plan, array $data): PlanEntitlement
    {
        $this->assertMeterExists($data['meter_id']);
        $this->assertMeterFree($plan, $data['meter_id'], null);

        return $plan->entitlements()->create($this->attributes($data));
    }

    /**
     * @param  array{meter_id: int, enabled: bool, unlimited: bool, allowance: int, multiplier: ?float, overage: OverageBehaviour}  $data
     */
    public function update(Plan $plan, PlanEntitlement $entitlement, array $data): PlanEntitlement
    {
        $this->assertMeterExists($data['meter_id']);
        $this->assertMeterFree($plan, $data['meter_id'], $entitlement->id);

        $entitlement->update($this->attributes($data));

        return $entitlement;
    }

    /** Remove the entitlement — the meter reverts to deny-by-default for the plan. */
    public function delete(PlanEntitlement $entitlement): void
    {
        $entitlement->delete();
    }

    /**
     * @param  array{meter_id: int, enabled: bool, unlimited: bool, allowance: int, multiplier: ?float, overage: OverageBehaviour}  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        // A disabled or unlimited bucket carries no costed allowance; normalize so the
        // projected policy is unambiguous (matches the seeder's metered/unlimited/disabled).
        $costed = $data['enabled'] && ! $data['unlimited'];

        return [
            'meter_id' => $data['meter_id'],
            'enabled' => $data['enabled'],
            'unlimited' => $data['enabled'] && $data['unlimited'],
            'allowance' => $costed ? $data['allowance'] : 0,
            'multiplier' => $costed && $data['multiplier'] !== null && $data['multiplier'] > 0.0 ? $data['multiplier'] : null,
            'overage' => $costed ? $data['overage'] : OverageBehaviour::Block,
        ];
    }

    private function assertMeterExists(int $meterId): void
    {
        if (! Meter::query()->whereKey($meterId)->exists()) {
            throw CatalogActionDenied::unknownMeter($meterId);
        }
    }

    private function assertMeterFree(Plan $plan, int $meterId, ?int $ignoreId): void
    {
        $taken = $plan->entitlements()
            ->where('meter_id', $meterId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw new CatalogActionDenied(sprintf(
                '%s already has an entitlement for this meter. Edit the existing one instead.',
                $plan->name,
            ));
        }
    }
}
