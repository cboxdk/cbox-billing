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
 * the account's currency selection, and the engine's proration + forfeiture lifecycle
 * are wired together; controllers and commands depend on this contract, never on the
 * concrete.
 */
interface SubscribesOrganizations
{
    /**
     * Subscribe `$organization` to `$plan`: open a subscription for the current period,
     * grant the plan's pool credit grants into the org's wallet, and (already, via the
     * meter-policy resolver) make its metered entitlements resolvable. When the account
     * has not yet chosen a billing currency, `$currency` (or the resolved default) is
     * pinned as its choice — the engine locks it one-way on the first finalized invoice.
     */
    public function subscribe(Organization $organization, Plan $plan, int $seats = 1, ?string $currency = null): Subscription;

    /**
     * The confirmable consequence of moving `$subscription` onto `$newPlan`, WITHOUT
     * applying it. Computed by the engine's proration in the account's currency; the
     * returned {@see PlanChangePreview} is the exact charge {@see changePlan()} will make
     * (preview == charge).
     */
    public function previewChange(Subscription $subscription, Plan $newPlan): PlanChangePreview;

    /**
     * Move an active subscription onto `$newPlan` and return the applied
     * {@see PlanChangePreview} — the same consequence {@see previewChange()} reported.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan): PlanChangePreview;

    /**
     * Cancel `$subscription`. `$atPeriodEnd` schedules the cancellation for the current
     * period end (the subscription stays active until then); otherwise the cancellation
     * is immediate — the engine lifecycle drives forfeiture-on-transition of the org's
     * forfeitable wallet pools as it leaves without landing on another plan.
     */
    public function cancel(Subscription $subscription, bool $atPeriodEnd): Subscription;
}
