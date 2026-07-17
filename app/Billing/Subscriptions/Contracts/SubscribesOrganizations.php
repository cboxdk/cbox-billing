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
     * Subscribe `$organization` to `$plan` in a FREE TRIAL: open the subscription
     * `Trialing` (serving the plan and provisioning its grants, but charging nothing) with
     * the trial due to convert `$trialDays` days out (or the configured default when null).
     * A zero/negative length is an ordinary paid subscribe. Conversion — the first charge —
     * is enacted later by {@see convertTrial()} on the scheduled convert pass.
     */
    public function subscribeWithTrial(Organization $organization, Plan $plan, ?int $trialDays = null, int $seats = 1, ?string $currency = null): Subscription;

    /**
     * Convert a due trial to a paying subscription via the engine's `Trialing` → `Active`
     * transition and clear its trial marker. The first charge is raised by the caller (the
     * convert pass), not here. Refuses a subscription that is not `Trialing`.
     */
    public function convertTrial(Subscription $subscription): Subscription;

    /**
     * A failed renewal charge: move a serving subscription to the engine's `PastDue` state
     * so the smart-retry schedule can chase the payment. Idempotent on an already-`PastDue`
     * subscription.
     */
    public function markPastDue(Subscription $subscription): Subscription;

    /** A recovered payment: the engine's `PastDue` → `Active` transition, persisted. */
    public function recover(Subscription $subscription): Subscription;

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
