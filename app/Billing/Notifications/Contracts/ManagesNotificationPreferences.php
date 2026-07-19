<?php

declare(strict_types=1);

namespace App\Billing\Notifications\Contracts;

use App\Billing\Notifications\BillingNotifier;
use App\Billing\Notifications\MailEventType;

/**
 * The per-organization notification-preference seam. The {@see BillingNotifier}
 * consults {@see allows()} before an OPTIONAL mail leaves; the portal reads {@see snapshot()}
 * to render the toggle list and writes {@see setOptedIn()} when a customer flips one. Both
 * sides depend on this contract, never on the concrete store, so the persistence can be
 * swapped without touching the notifier or the controller.
 */
interface ManagesNotificationPreferences
{
    /**
     * Whether an OPTIONAL mail of `$event` may be delivered to `$organizationId` — true by
     * default (opted in) unless the org has explicitly opted out. A MANDATORY event is always
     * allowed: preferences never suppress a transactional/legal mail.
     */
    public function allows(string $organizationId, MailEventType $event): bool;

    /**
     * The org's opt-in state for every optional event, defaulting to opted-in where no row
     * exists — the shape the portal renders its toggles from.
     *
     * @return array<string, bool> keyed by {@see MailEventType::value}
     */
    public function snapshot(string $organizationId): array;

    /**
     * Persist the org's opt-in state for one optional event (idempotent upsert). A mandatory
     * event is rejected as a no-op returning false, so a caller can never opt out of a legal
     * mail through this seam.
     */
    public function setOptedIn(string $organizationId, MailEventType $event, bool $optedIn): bool;
}
