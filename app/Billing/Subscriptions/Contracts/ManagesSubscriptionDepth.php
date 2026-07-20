<?php

declare(strict_types=1);

namespace App\Billing\Subscriptions\Contracts;

use App\Billing\Subscriptions\ValueObjects\AddOnPreview;
use App\Billing\Subscriptions\ValueObjects\AddOnRequest;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;

/**
 * Subscription-management depth over the engine's lifecycle (ADR-0012): pause/resume,
 * seat-quantity changes with preview-equals-charge proration, aligned/independent
 * add-ons, and deferred (change-at-period-end) plan changes surfaced distinctly from
 * immediate ones. Controllers and the console depend on this contract, never on the
 * concrete service.
 */
interface ManagesSubscriptionDepth
{
    /** Suspend access and metering (no renewal) until resumed; idempotent. */
    public function pause(Subscription $subscription): Subscription;

    /** Lift a pause, restoring access and metering; idempotent. */
    public function resume(Subscription $subscription): Subscription;

    /**
     * The prorated consequence of moving `$subscription` to `$seats` seats over the days
     * still to run — the exact charge {@see changeQuantity()} makes (preview == charge).
     */
    public function previewQuantity(Subscription $subscription, int $seats): QuantityPreview;

    /**
     * Apply the seat-quantity change: charge the prorated delta, re-establish the
     * per-seat allotment for the new count, and persist the seats. Returns the same
     * preview {@see previewQuantity()} reported.
     */
    public function changeQuantity(Subscription $subscription, int $seats): QuantityPreview;

    /**
     * The prorated consequence of attaching `$request` — the add-on's charge for the days
     * still to run (aligned to the base period or its own independent cycle) and the
     * credit allotment it would grant — WITHOUT attaching it (preview == charge).
     */
    public function previewAddOn(Subscription $subscription, AddOnRequest $request): AddOnPreview;

    /** Attach (or replace) the add-on: persist it, charge the prorated amount, grant its allotment. */
    public function addAddOn(Subscription $subscription, AddOnRequest $request): SubscriptionAddOn;

    /** Detach the add-on by key; returns whether one was attached. */
    public function removeAddOn(Subscription $subscription, string $key): bool;

    /**
     * Schedule a deferred plan change for the current period end — gated by the same
     * transition policy an immediate change is, then stored to enact at renewal rather
     * than applied now.
     */
    public function scheduleChange(Subscription $subscription, Plan $newPlan): PlanChangePreview;

    /**
     * Enact every scheduled change that has come due (its effective instant has passed),
     * applying the stored plan change and clearing the pending state. Returns the count
     * enacted.
     */
    public function applyDueScheduledChanges(): int;
}
