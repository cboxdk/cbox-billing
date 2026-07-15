<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\Contracts;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;

/**
 * Subscribes organizations to plans and moves them between plans. The concrete service
 * is the one place the durable {@see Subscription} row, the wallet grants a plan issues,
 * and the engine's proration are wired together; controllers and commands depend on this
 * contract, never on the concrete.
 */
interface SubscribesOrganizations
{
    /**
     * Subscribe `$organization` to `$plan`: open a subscription for the current period,
     * grant the plan's pool credit grants into the org's wallet, and (already, via the
     * meter-policy resolver) make its metered entitlements resolvable.
     */
    public function subscribe(Organization $organization, Plan $plan, int $seats = 1): Subscription;

    /**
     * Move an active subscription onto `$newPlan`. The consequence is computed by the
     * engine's proration (preview == charge): the returned {@see PlanChangePreview} carries
     * the exact amount due now, which the caller confirms into an invoice.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan): PlanChangePreview;
}
