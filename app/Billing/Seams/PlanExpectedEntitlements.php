<?php

declare(strict_types=1);

namespace App\Billing\Seams;

use App\Models\Subscription;
use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditTarget;

/**
 * The INDEPENDENT oracle the entitlement audit checks resolved rows against: every org
 * with an active subscription, carrying the meter keys its plan is DEFINED to grant.
 *
 * The expected set is derived from plan/catalog definition — the plan's enabled
 * metered entitlements — and NEVER read back out of the resolved policy the audit then
 * inspects. That independence is the whole point: only an oracle built separately from
 * the resolved rows can notice a row is missing.
 *
 * @see SubscriptionMeterPolicyResolver the resolved side the audit compares against
 */
readonly class PlanExpectedEntitlements implements ExpectedEntitlements
{
    public function targets(): iterable
    {
        $subscriptions = Subscription::query()
            ->where('status', 'active')
            ->whereNull('paused_at')
            ->with(['plan.entitlements.meter'])
            ->get();

        foreach ($subscriptions as $subscription) {
            $plan = $subscription->plan;

            if ($plan === null) {
                continue;
            }

            $expectedKeys = [];

            foreach ($plan->entitlements as $entitlement) {
                $key = $entitlement->meter?->key;

                if ($entitlement->enabled && $key !== null) {
                    $expectedKeys[] = $key;
                }
            }

            yield new AuditTarget($subscription->organization_id, $plan->key, $expectedKeys);
        }
    }
}
