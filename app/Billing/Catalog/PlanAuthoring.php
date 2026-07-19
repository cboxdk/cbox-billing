<?php

declare(strict_types=1);

namespace App\Billing\Catalog;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Cbox\Billing\Catalog\Enums\PlanStatus;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Illuminate\Database\ConnectionInterface;

/**
 * Create / edit / archive / delete a {@see Plan}. Plan authoring is METADATA only — name,
 * interval, owning product, active flag — because a plan's money lives in the versioned
 * per-currency {@see PlanPrice} authoring, which grandfathers existing
 * subscribers. Editing a plan here therefore never reprices anyone: it only touches the
 * plan's descriptive fields and its offered/legacy state.
 *
 * Archiving sets `active = false`, which projects the plan as
 * {@see PlanStatus::Legacy} — a valid transition source that
 * is never offered to new customers, while every current subscriber keeps their plan and
 * their grandfathered price. A plan is hard-deleted only when NO subscription (serving or
 * historical) references it; otherwise the delete is refused and archive is offered.
 */
readonly class PlanAuthoring
{
    /**
     * The intervals the billing engine can renew — {@see BillingInterval}
     * carries only Monthly and Yearly. Deny-by-default: authoring any other cadence is
     * refused here (not only in the controller), so no plan can be created on an interval
     * that would then be silently billed monthly.
     */
    private const BILLABLE_INTERVALS = ['month', 'year'];

    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array{product_id: int, key: string, name: string, interval: string, active: bool}  $data
     */
    public function create(array $data): Plan
    {
        $this->assertKeyUnique($data['key'], null);
        $this->assertBillableInterval($data['interval']);

        return Plan::query()->create([
            'product_id' => $data['product_id'],
            'key' => $data['key'],
            'name' => $data['name'],
            'interval' => $data['interval'],
            'active' => $data['active'],
        ]);
    }

    /**
     * @param  array{product_id: int, key: string, name: string, interval: string, active: bool}  $data
     */
    public function update(Plan $plan, array $data): Plan
    {
        $this->assertKeyUnique($data['key'], $plan->id);
        $this->assertBillableInterval($data['interval']);

        // Only metadata — never the price. Grandfathering is preserved because the
        // per-currency PlanPrice rows a subscriber grandfathered onto are untouched.
        $plan->update([
            'product_id' => $data['product_id'],
            'key' => $data['key'],
            'name' => $data['name'],
            'interval' => $data['interval'],
            'active' => $data['active'],
        ]);

        return $plan;
    }

    /** Make the plan legacy (closed to new signups); current subscribers keep it. */
    public function archive(Plan $plan): void
    {
        $plan->forceFill(['active' => false])->save();
    }

    /** Re-offer an archived (legacy) plan to new signups. */
    public function unarchive(Plan $plan): void
    {
        $plan->forceFill(['active' => true])->save();
    }

    /**
     * Hard-delete a plan and its child catalog rows (prices + tiers, entitlements, credit
     * grants) — refused while ANY subscription references it, so no subscriber is left
     * pointing at a deleted plan. Archive keeps them on their grandfathered price instead.
     */
    public function delete(Plan $plan): void
    {
        $subscribers = Subscription::query()->where('plan_id', $plan->id)->count();

        if ($subscribers > 0) {
            throw CatalogActionDenied::planHasSubscribers($plan->name, $subscribers);
        }

        $this->db->transaction(function () use ($plan): void {
            foreach ($plan->prices as $price) {
                $price->tiers()->delete();
            }

            $plan->prices()->delete();
            $plan->entitlements()->delete();
            $plan->creditGrants()->delete();

            // Clear any successor pointer aimed at this plan before it goes.
            Plan::query()->where('default_successor_plan_id', $plan->id)
                ->update(['default_successor_plan_id' => null]);

            $plan->delete();
        });
    }

    private function assertBillableInterval(string $interval): void
    {
        if (! in_array(strtolower($interval), self::BILLABLE_INTERVALS, true)) {
            throw CatalogActionDenied::unbillableInterval($interval);
        }
    }

    private function assertKeyUnique(string $key, ?int $ignoreId): void
    {
        $exists = Plan::query()
            ->where('key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw CatalogActionDenied::duplicateKey($key);
        }
    }
}
