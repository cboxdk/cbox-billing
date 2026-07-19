<?php

declare(strict_types=1);

namespace App\Billing\Notifications;

use App\Billing\Notifications\Contracts\ManagesNotificationPreferences;
use App\Models\NotificationPreference;

/**
 * Reads and writes the per-org opt-in state for the optional lifecycle notifications, over
 * the {@see NotificationPreference} rows. Deny-by-default is inverted here on purpose: the
 * ABSENCE of a row means opted-in (a customer who never touched the toggles still gets the
 * courtesy mails), so a missing row reads `true` and only an explicit opt-out suppresses.
 *
 * A mandatory event can never be suppressed through this service: {@see allows()} short-
 * circuits to true for it and {@see setOptedIn()} refuses to write one, so the store only
 * ever holds optional-event rows.
 */
readonly class NotificationPreferenceService implements ManagesNotificationPreferences
{
    public function allows(string $organizationId, MailEventType $event): bool
    {
        if (! $event->isOptional()) {
            return true;
        }

        $preference = NotificationPreference::query()
            ->where('organization_id', $organizationId)
            ->where('event_type', $event->value)
            ->first();

        return $preference === null || $preference->opted_in;
    }

    public function snapshot(string $organizationId): array
    {
        $rows = NotificationPreference::query()
            ->where('organization_id', $organizationId)
            ->pluck('opted_in', 'event_type');

        $snapshot = [];

        foreach (MailEventType::optional() as $event) {
            // Default opted-in: a customer who never changed a toggle still gets the courtesy mail.
            $snapshot[$event->value] = (bool) ($rows[$event->value] ?? true);
        }

        return $snapshot;
    }

    public function setOptedIn(string $organizationId, MailEventType $event, bool $optedIn): bool
    {
        // The store only ever holds optional-event rows — a mandatory event is never suppressible.
        if (! $event->isOptional()) {
            return false;
        }

        NotificationPreference::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'event_type' => $event->value],
            ['opted_in' => $optedIn],
        );

        return true;
    }
}
