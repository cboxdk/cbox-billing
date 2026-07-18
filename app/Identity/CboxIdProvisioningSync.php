<?php

declare(strict_types=1);

namespace App\Identity;

use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
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
 *     billing org — added/removed/re-roled by the membership + role events.
 *  2. SEAT COUNTS — a member added/removed nudges the org's serving subscription by one
 *     seat through the engine's own {@see ManagesSubscriptionDepth::changeQuantity()}, the
 *     exact primitive seat MRR is derived from (prorated charge/credit + re-established
 *     per-seat allotment). No serving subscription ⇒ mirror only, no seat move.
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
        private ManagesSubscriptionDepth $depth,
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

    /** A member joined the org: mirror the (org, subject, role) grant and add a seat. */
    private function memberAdded(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);

        if ($org === null || $subject === null) {
            return;
        }

        $this->putGrant($org, $subject, $this->role($event) ?? CboxIdAccessGrant::NO_ROLE, $this->environment($event));
        $this->adjustSeats($org, +1);
    }

    /** A member left the org: drop every grant it held and release a seat. */
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

        $this->adjustSeats($org, -1);
    }

    /** A member's role changed: replace its assigned roles with the new one (no seat move). */
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
    }

    /** A role was assigned to a subject: mirror the (org, subject, role) grant. */
    private function roleAssigned(WebhookEvent $event): void
    {
        $org = $this->org($event);
        $subject = $this->subject($event);
        $role = $this->role($event);

        if ($org === null || $subject === null || $role === null) {
            return;
        }

        $this->putGrant($org, $subject, $role, $this->environment($event));
    }

    /** A role was revoked from a subject: drop that (org, subject, role) grant. */
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
    }

    /** A directory (SCIM) user was provisioned: pre-create a bare membership, no seat move. */
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
     * Nudge the org's serving subscription by `$delta` seats through the engine's own
     * quantity-change primitive (prorated charge/credit + re-established allotment + seat
     * MRR movement). Seats never fall below 1. No serving subscription ⇒ no-op.
     */
    private function adjustSeats(string $org, int $delta): void
    {
        $subscription = Subscription::query()
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();

        if (! $subscription instanceof Subscription) {
            return;
        }

        $target = max(1, $subscription->seats + $delta);

        if ($target === $subscription->seats) {
            return;
        }

        $this->depth->changeQuantity($subscription, $target);
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
