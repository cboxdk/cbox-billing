<?php

declare(strict_types=1);

namespace App\Billing\Seats\Contracts;

use App\Billing\Seats\Enums\SeatSource;
use App\Billing\Seats\Exceptions\SeatException;
use App\Billing\Seats\ValueObjects\SeatBreakdown;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Models\SeatAssignment;
use App\Models\Subscription;

/**
 * The purchased + explicitly-assigned seat model. Purchased Full seats ARE the serving
 * subscription's seat quantity — the only billing driver — and are changed through the
 * engine's own {@see ManagesSubscriptionDepth::changeQuantity()}
 * (prorated charge/credit + MRR movement). Assignment is app-side eligibility bookkeeping
 * over the access mirror and never moves the billed quantity.
 *
 * Invariant enforced throughout: assigned count ≤ purchased seats. Controllers, the
 * console, and the webhook sync depend on this contract, never on the concrete service.
 */
interface ManagesSeats
{
    /**
     * Set the org's purchased Full-seat count to `$seats` (absolute) through the engine's
     * quantity-change primitive — the buy/release control. Refuses dropping below the
     * assigned count or below one.
     *
     * @throws SeatException
     */
    public function setPurchased(Subscription $subscription, int $seats): QuantityPreview;

    /**
     * Assign one free purchased seat to `$subject` (drawn from the eligibility mirror).
     * Idempotent: re-assigning a seated subject returns the existing row. Refuses when no
     * purchased seat is free or the subject is not an eligible member.
     *
     * @throws SeatException
     */
    public function assign(Subscription $subscription, string $subject, SeatSource $source = SeatSource::Manual): SeatAssignment;

    /**
     * Attempt an AUTO assignment for `$subject` under the auto-assign policy: only when
     * auto-assign is enabled, the subject's role is in the auto-assign set, a seat is free,
     * and the subject is not already seated. Returns the assignment when made, else null —
     * it never throws (a full cap simply leaves the member Light).
     */
    public function autoAssign(Subscription $subscription, string $subject, string $role): ?SeatAssignment;

    /** Release `$subject`'s seat regardless of source (explicit unassign / member removed). Returns whether one was freed. */
    public function unassign(string $organizationId, string $subject): bool;

    /**
     * Release `$subject`'s seat ONLY if it is auto-sourced — the role-drop path. A manual
     * seat is never auto-released. Returns whether one was freed.
     */
    public function releaseAutoAssigned(string $organizationId, string $subject): bool;

    /** The seat picture for an organization: purchased, assigned, and the Full/Light member lists. */
    public function breakdown(Subscription $subscription): SeatBreakdown;
}
