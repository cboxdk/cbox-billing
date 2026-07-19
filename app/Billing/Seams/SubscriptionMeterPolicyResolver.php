<?php

declare(strict_types=1);

namespace App\Billing\Seams;

use App\Models\Meter;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

/**
 * Resolves the per-bucket {@see MeterPolicy} for an (org, meter) from the org's serving
 * subscription → plan → metered entitlement. This is entitlement's answer to "what is
 * this dimension granted?", read live from the durable catalog. Serving is the engine's
 * {@see SubscriptionStatus::isServing()} set (via
 * {@see Subscription::scopeServing()}), so a trialing, past-due or non-renewing customer
 * keeps its metered entitlements exactly as an active one does.
 *
 * **Deny-by-default:** an org with no serving subscription, a **paused** subscription,
 * or a plan with no entitlement row for the meter, resolves to `null` — and the enforcer
 * refuses it. A metered dimension is never silently trusted; pausing suspends metering.
 *
 * **Request-level memoization (PERF-2):** {@see EntitlementsView} resolves EVERY meter for an
 * org in a loop, and the naive path re-queries the serving subscription and the meter row per
 * meter — 3 queries × meters. Here the serving subscription, the plan's entitlement rows and
 * the meter catalog are read ONCE per org and cached on the instance, so each meter resolves
 * from memory. The resolver is a per-request container singleton, so the memo lives exactly
 * one request; {@see flush()} clears it should a caller mutate the catalog and re-resolve
 * within the same request.
 */
class SubscriptionMeterPolicyResolver implements MeterPolicyResolver
{
    /**
     * Per-org resolution context, memoized for the request.
     *
     * @var array<string, array{plan_id: int|null, meters: array<string, Meter>, entitlements: array<int, PlanEntitlement>}>
     */
    private array $memo = [];

    public function resolve(string $org, string $meter): ?MeterPolicy
    {
        $context = $this->contextFor($org);

        if ($context['plan_id'] === null) {
            return null;
        }

        $meterRow = $context['meters'][$meter] ?? null;

        if (! $meterRow instanceof Meter) {
            return null;
        }

        $entitlement = $context['entitlements'][$meterRow->id] ?? null;

        if (! $entitlement instanceof PlanEntitlement) {
            return null;
        }

        // The meter row is already in hand — hand it to the entitlement so its policy reads
        // the meter's aggregation without a second query.
        $entitlement->setRelation('meter', $meterRow);

        return $entitlement->toMeterPolicy();
    }

    /** Drop the memoized per-org context (e.g. after a catalog/subscription change mid-request). */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * The org's resolution context, loaded once and memoized: its serving plan, the meter
     * catalog keyed by key, and the plan's entitlement rows keyed by meter id. An org with no
     * serving subscription memoizes an empty context (deny-by-default) without re-querying.
     *
     * @return array{plan_id: int|null, meters: array<string, Meter>, entitlements: array<int, PlanEntitlement>}
     */
    private function contextFor(string $org): array
    {
        if (isset($this->memo[$org])) {
            return $this->memo[$org];
        }

        $subscription = Subscription::query()
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();

        if (! $subscription instanceof Subscription) {
            return $this->memo[$org] = ['plan_id' => null, 'meters' => [], 'entitlements' => []];
        }

        /** @var array<string, Meter> $meters */
        $meters = Meter::query()->get()->keyBy('key')->all();

        /** @var array<int, PlanEntitlement> $entitlements */
        $entitlements = PlanEntitlement::query()
            ->where('plan_id', $subscription->plan_id)
            ->get()
            ->keyBy('meter_id')
            ->all();

        return $this->memo[$org] = [
            'plan_id' => $subscription->plan_id,
            'meters' => $meters,
            'entitlements' => $entitlements,
        ];
    }
}
