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
 */
readonly class SubscriptionMeterPolicyResolver implements MeterPolicyResolver
{
    public function resolve(string $org, string $meter): ?MeterPolicy
    {
        $subscription = Subscription::query()
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();

        if (! $subscription instanceof Subscription) {
            return null;
        }

        $meterRow = Meter::query()->where('key', $meter)->first();

        if (! $meterRow instanceof Meter) {
            return null;
        }

        $entitlement = PlanEntitlement::query()
            ->where('plan_id', $subscription->plan_id)
            ->where('meter_id', $meterRow->id)
            ->first();

        return $entitlement instanceof PlanEntitlement
            ? $entitlement->toMeterPolicy()
            : null;
    }
}
