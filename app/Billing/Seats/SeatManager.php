<?php

declare(strict_types=1);

namespace App\Billing\Seats;

use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\Enums\SeatSource;
use App\Billing\Seats\Exceptions\SeatException;
use App\Billing\Seats\ValueObjects\SeatBreakdown;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Models\CboxIdAccessGrant;
use App\Models\SeatAssignment;
use App\Models\Subscription;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The purchased + explicitly-assigned seat model (replacing the old per-member auto-nudge).
 *
 * PURCHASED Full seats are the serving subscription's seat quantity — the ONLY billing
 * driver. Buying/releasing seats delegates to the engine's own
 * {@see ManagesSubscriptionDepth::changeQuantity()} (prorated charge/credit + re-established
 * per-seat allotment + MRR movement); no membership event ever moves the billed quantity.
 *
 * ASSIGNMENT is app-side bookkeeping over the access mirror: one {@see SeatAssignment} row
 * hands a purchased Full seat to a specific eligible member. The invariant is
 * `assigned ≤ purchased`, enforced under a row lock on both assign and release. A member in
 * the mirror without an assignment is Light (counted, never billed).
 */
readonly class SeatManager implements ManagesSeats
{
    public function __construct(
        private ConnectionInterface $db,
        private ManagesSubscriptionDepth $depth,
    ) {}

    public function setPurchased(Subscription $subscription, int $seats): QuantityPreview
    {
        if ($seats < 1) {
            throw SeatException::belowOne();
        }

        $assigned = $this->assignedCount($subscription->organization_id);

        if ($seats < $assigned) {
            throw SeatException::belowAssigned($seats, $assigned);
        }

        // A no-op target neither charges nor forfeits/re-grants the wallet — return the
        // (zero) preview without touching the subscription.
        if ($seats === $subscription->seats) {
            return $this->depth->previewQuantity($subscription, $seats);
        }

        return $this->depth->changeQuantity($subscription, $seats);
    }

    public function assign(Subscription $subscription, string $subject, SeatSource $source = SeatSource::Manual): SeatAssignment
    {
        $org = $subscription->organization_id;

        return $this->db->transaction(function () use ($subscription, $org, $subject, $source): SeatAssignment {
            $existing = SeatAssignment::query()
                ->where('organization_id', $org)
                ->where('subject', $subject)
                ->lockForUpdate()
                ->first();

            // Idempotent: a seated subject keeps its existing seat (and its source).
            if ($existing instanceof SeatAssignment) {
                return $existing;
            }

            if (! $this->isEligible($org, $subject)) {
                throw SeatException::notEligible($subject);
            }

            $purchased = $subscription->seats;
            $assigned = SeatAssignment::query()->where('organization_id', $org)->lockForUpdate()->count();

            if ($assigned >= $purchased) {
                throw SeatException::noFreeSeat($purchased, $assigned);
            }

            return SeatAssignment::query()->create([
                'organization_id' => $org,
                'subject' => $subject,
                'source' => $source,
                'assigned_at' => Carbon::now(),
            ]);
        });
    }

    public function autoAssign(Subscription $subscription, string $subject, string $role): ?SeatAssignment
    {
        if (! $this->autoAssignEnabled()) {
            return null;
        }

        if (! in_array($role, $this->autoAssignRoles(), true)) {
            return null;
        }

        if ($this->isSeated($subscription->organization_id, $subject)) {
            return null;
        }

        try {
            return $this->assign($subscription, $subject, SeatSource::Auto);
        } catch (SeatException) {
            // No free seat (or no longer eligible): leave the member Light. Never auto-buy.
            return null;
        }
    }

    public function unassign(string $organizationId, string $subject): bool
    {
        return SeatAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('subject', $subject)
            ->delete() > 0;
    }

    public function releaseAutoAssigned(string $organizationId, string $subject): bool
    {
        return SeatAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('subject', $subject)
            ->where('source', SeatSource::Auto->value)
            ->delete() > 0;
    }

    public function breakdown(Subscription $subscription): SeatBreakdown
    {
        $org = $subscription->organization_id;

        $roles = $this->eligibleRoles($org);
        $assignments = SeatAssignment::query()
            ->where('organization_id', $org)
            ->get()
            ->keyBy('subject');

        $full = [];
        $light = [];
        $assignable = [];

        foreach ($roles as $subject => $role) {
            $assignment = $assignments->get($subject);

            if ($assignment instanceof SeatAssignment) {
                $full[] = [
                    'subject' => $subject,
                    'role' => $role,
                    'source' => $assignment->source->value,
                    'assigned_at' => $assignment->assigned_at?->format('Y-m-d'),
                ];

                continue;
            }

            $member = ['subject' => $subject, 'role' => $role];
            $light[] = $member;
            $assignable[] = $member;
        }

        return new SeatBreakdown(
            purchased: $subscription->seats,
            assigned: $assignments->count(),
            full: $full,
            light: $light,
            assignable: $assignable,
        );
    }

    /** The count of purchased seats currently assigned to members. */
    private function assignedCount(string $organizationId): int
    {
        return SeatAssignment::query()->where('organization_id', $organizationId)->count();
    }

    private function isSeated(string $organizationId, string $subject): bool
    {
        return SeatAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('subject', $subject)
            ->exists();
    }

    /** A subject is eligible when it appears in the access mirror for the org. */
    private function isEligible(string $organizationId, string $subject): bool
    {
        return CboxIdAccessGrant::query()
            ->where('organization_id', $organizationId)
            ->where('subject', $subject)
            ->exists();
    }

    /**
     * The distinct eligible members of an org and a representative role for each — the first
     * non-empty role a subject holds, or the bare-membership marker when it holds none.
     *
     * @return array<string, string>
     */
    private function eligibleRoles(string $organizationId): array
    {
        $roles = [];

        $grants = CboxIdAccessGrant::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('role')
            ->get(['subject', 'role']);

        foreach ($grants as $grant) {
            $current = $roles[$grant->subject] ?? CboxIdAccessGrant::NO_ROLE;

            if ($current === CboxIdAccessGrant::NO_ROLE) {
                $roles[$grant->subject] = $grant->role;
            }
        }

        return $roles;
    }

    private function autoAssignEnabled(): bool
    {
        return (bool) config('billing.seats.auto_assign', false);
    }

    /** @return list<string> */
    private function autoAssignRoles(): array
    {
        $roles = config('billing.seats.auto_assign_roles', []);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }
}
