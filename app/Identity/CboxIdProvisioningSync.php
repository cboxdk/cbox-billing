<?php

declare(strict_types=1);

namespace App\Identity;

use App\Billing\Seats\Contracts\ManagesSeats;
use App\Identity\Contracts\SyncsIdentityProvisioning;
use App\Models\CboxIdAccessGrant;
use App\Models\Organization;
use App\Models\Subscription;
use Cbox\Id\Client\Webhooks\WebhookEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Reacts to Cbox ID's verified provisioning webhooks so the billing side stays fresh
 * out-of-band — no token round-trip, no polling. Cbox ID owns identity and role
 * assignment; this keeps three derived facts current:
 *
 *  1. The ACCESS MIRROR ({@see CboxIdAccessGrant}) — which subject/role may act on which
 *     billing org, i.e. the ELIGIBILITY for a seat — added/removed/re-roled by the
 *     membership + role events.
 *  2. SEAT ASSIGNMENTS — under the purchased + explicitly-assigned model, membership NEVER
 *     touches the billed quantity (purchased Full seats = the subscription quantity, changed
 *     only by an explicit admin buy/release). Instead, when AUTO-ASSIGN is enabled, a joining
 *     management-role member is given a FREE purchased seat if one is available (never
 *     auto-buys, never exceeds the cap); a removed member's seat is released for reuse while
 *     the org keeps the seat it paid for. See {@see ManagesSeats}.
 *  3. ORG STANDING — suspend/reactivate stamps `organizations.suspended_at`.
 *
 * Exactly-once per delivery: the envelope's `delivery_id` is claimed with a UNIQUE insert
 * in the SAME transaction as the effect, so a re-delivery (or a crash mid-apply) neither
 * double-applies nor drops the event — a claimed id short-circuits to a no-op.
 */
readonly class CboxIdProvisioningSync implements SyncsIdentityProvisioning
{
    public function __construct(
        private ConnectionInterface $db,
        private ManagesSeats $seats,
    ) {}

    public function handle(WebhookEvent $event): void
    {
        $this->db->transaction(function () use ($event): void {
            $deliveryId = $event->deliveryId;

            // Claim the delivery id (dedup) and apply the effect atomically. A replay
            // inserts nothing, so we short-circuit before touching mirror or seats.
            if ($deliveryId !== null && $deliveryId !== '') {
                $claimed = $this->db->table('cbox_id_webhook_deliveries')->insertOrIgnore([
                    'delivery_id' => $deliveryId,
                    'event_type' => $event->type,
                    'organization_id' => $event->organizationId,
                    'processed_at' => Carbon::now(),
                ]);

                if ($claimed === 0) {
                    return;
                }
            }

            $this->apply($event);
        });
    }

    private function apply(WebhookEvent $event): void
    {
        match ($event->type) {
            'organization.member_added' => $this->memberAdded($event),
            'organization.member_removed' => $this->memberRemoved($event),
            'organization.member_role_changed' => $this->memberRoleChanged($event),
            'role.assigned' => $this->roleAssigned($event),
            'role.revoked' => $this->roleRevoked($event),
            'directory.user.provisioned' => $this->userProvisioned($event),
            'organization.suspended' => $this->setOrgSuspended($event, suspended: true),
            'organization.reactivated' => $this->setOrgSuspended($event, suspended: false),
            default => null,
        };
    }

    /**
     * A member joined the org: mirror the (org, subject, role) grant (eligibility). The
     * billed quantity is untouched — with auto-assign OFF the member is Light; with
     * auto-assign ON and a management role, a free purchased seat is auto-assigned.
     */
    private function memberAdded(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);

        if ($org === null || $subject === null) {
            return;
        }

        $role = $this->role($event) ?? CboxIdAccessGrant::NO_ROLE;
        $this->putGrant($org, $subject, $role, $this->environment($event));
        $this->tryAutoAssign($org, $subject, $role);
    }

    /**
     * A member left the org: drop every grant it held (eligibility gone) and RELEASE its
     * seat assignment so the seat can be reused — but the purchased count is NOT reduced,
     * the org keeps the seat it paid for until an admin explicitly releases it.
     */
    private function memberRemoved(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);

        if ($org === null || $subject === null) {
            return;
        }

        CboxIdAccessGrant::query()
            ->where('organization_id', $org)
            ->where('subject', $subject)
            ->delete();

        $this->seats->unassign($org, $subject);
    }

    /**
     * A member's role changed: replace its assigned roles with the new one (eligibility
     * update, no billed-quantity move). Under auto-assign, a new management role claims a
     * free seat; a role that drops OUT of the auto-assign set releases an AUTO seat (never a
     * manual one).
     */
    private function memberRoleChanged(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);
        $role = $this->role($event);

        if ($org === null || $subject === null || $role === null) {
            return;
        }

        CboxIdAccessGrant::query()
            ->where('organization_id', $org)
            ->where('subject', $subject)
            ->where('role', '!=', CboxIdAccessGrant::NO_ROLE)
            ->delete();

        $this->putGrant($org, $subject, $role, $this->environment($event));

        if (! $this->autoAssignEnabled()) {
            return;
        }

        if ($this->isAutoAssignRole($role)) {
            // Still seat-worthy: ensure a seat if one is free (a member already seated keeps
            // its seat — auto-assign no-ops).
            $this->tryAutoAssign($org, $subject, $role);

            return;
        }

        // Dropped out of the seat-worthy set — release an AUTO seat the member held under its
        // previous role (a manual seat is never auto-released).
        $this->seats->releaseAutoAssigned($org, $subject);
    }

    /** A role was assigned to a subject: mirror the grant; auto-assign a free seat when enabled. */
    private function roleAssigned(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);
        $role = $this->role($event);

        if ($org === null || $subject === null || $role === null) {
            return;
        }

        $this->putGrant($org, $subject, $role, $this->environment($event));
        $this->tryAutoAssign($org, $subject, $role);
    }

    /**
     * A role was revoked from a subject: drop that (org, subject, role) grant. Under
     * auto-assign, if the subject no longer holds ANY seat-worthy role, release its auto seat.
     */
    private function roleRevoked(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);
        $role = $this->role($event);

        if ($org === null || $subject === null || $role === null) {
            return;
        }

        CboxIdAccessGrant::query()
            ->where('organization_id', $org)
            ->where('subject', $subject)
            ->where('role', $role)
            ->delete();

        if ($this->autoAssignEnabled() && ! $this->holdsAutoAssignRole($org, $subject)) {
            $this->seats->releaseAutoAssigned($org, $subject);
        }
    }

    /** A directory (SCIM) user was provisioned: pre-create a bare membership (eligibility, Light). */
    private function userProvisioned(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);

        if ($org === null || $subject === null) {
            return;
        }

        $this->putGrant($org, $subject, CboxIdAccessGrant::NO_ROLE, $this->environment($event));
    }

    /** Reflect an org suspend/reactivate on the billing tenant's `suspended_at` marker. */
    private function setOrgSuspended(WebhookEvent $event, bool $suspended): void
    {
        $org = $this->org($event);

        if ($org === null) {
            return;
        }

        Organization::query()
            ->whereKey($org)
            ->update(['suspended_at' => $suspended ? Carbon::now() : null]);
    }

    /**
     * Try to auto-assign a free purchased seat to a member holding `$role`. No-op (returns
     * false) when auto-assign is off, the role is not seat-worthy, no serving subscription
     * exists, or no seat is free — the member simply stays Light. Never auto-buys.
     */
    private function tryAutoAssign(string $org, string $subject, string $role): bool
    {
        $subscription = $this->servingSubscription($org);

        if (! $subscription instanceof Subscription) {
            return false;
        }

        return $this->seats->autoAssign($subscription, $subject, $role) !== null;
    }

    /** The org's serving subscription (the seat authority), or null when it has none. */
    private function servingSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();
    }

    /** Whether the subject still holds any role in the auto-assign set (mirror-derived). */
    private function holdsAutoAssignRole(string $org, string $subject): bool
    {
        $roles = $this->autoAssignRoles();

        if ($roles === []) {
            return false;
        }

        return CboxIdAccessGrant::query()
            ->where('organization_id', $org)
            ->where('subject', $subject)
            ->whereIn('role', $roles)
            ->exists();
    }

    /** Upsert an access-mirror grant, only stamping `environment_key` when the event carries it. */
    private function putGrant(string $org, string $subject, string $role, ?string $environment): void
    {
        $attributes = [];

        if ($environment !== null) {
            $attributes['environment_key'] = $environment;
        }

        CboxIdAccessGrant::query()->updateOrCreate(
            ['organization_id' => $org, 'subject' => $subject, 'role' => $role],
            $attributes,
        );
    }

    private function autoAssignEnabled(): bool
    {
        return (bool) config('billing.seats.auto_assign', false);
    }

    private function isAutoAssignRole(string $role): bool
    {
        return $role !== CboxIdAccessGrant::NO_ROLE && in_array($role, $this->autoAssignRoles(), true);
    }

    /** @return list<string> */
    private function autoAssignRoles(): array
    {
        $roles = config('billing.seats.auto_assign_roles', []);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    private function org(WebhookEvent $event): ?string
    {
        return $this->nonEmpty($event->organizationId ?? $event->string('organization_id'));
    }

    private function subject(WebhookEvent $event): ?string
    {
        return $this->nonEmpty($event->string('user_id') ?? $event->string('subject'));
    }

    /**
     * The role slug carried by the event. Cbox ID's contract puts it under `role_id` on the
     * role.assigned/role.revoked events and under `role` on the membership events — so read
     * `role_id` first, then fall back to `role`/`role_key`.
     */
    private function role(WebhookEvent $event): ?string
    {
        return $this->nonEmpty($event->string('role_id') ?? $event->string('role') ?? $event->string('role_key'));
    }

    private function environment(WebhookEvent $event): ?string
    {
        return $this->nonEmpty($event->string('environment'));
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $value : null;
    }
}
