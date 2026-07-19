<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One organization's opt-in state for a single OPTIONAL lifecycle notification. A row
 * exists only once a customer has changed the default (opted in): its absence is itself
 * the "opted in" answer, so the portal toggle writes/updates a row and the notifier reads
 * `opted_in` with a default-true fallback. Mandatory mails are never represented here.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $event_type
 * @property bool $opted_in
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'organization_id', 'event_type', 'opted_in',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opted_in' => 'boolean'];
    }
}
