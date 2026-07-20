<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Model;

/**
 * One organization's opt-in state for a single OPTIONAL lifecycle notification. A row
 * exists only once a customer has changed the default (opted in): its absence is itself
 * the "opted in" answer, so the portal toggle writes/updates a row and the notifier reads
 * `opted_in` with a default-true fallback. Mandatory mails are never represented here.
 *
 * Plane-scoped: a preference belongs to one billing ENVIRONMENT (via {@see BelongsToEnvironment}),
 * so a sandbox opt-out never suppresses the same org's production emails (and is torn down with
 * the sandbox). `environment` is stamped from the ambient plane, never mass-assignable.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $environment
 * @property string $event_type
 * @property bool $opted_in
 */
class NotificationPreference extends Model
{
    use BelongsToEnvironment;

    protected $fillable = [
        'organization_id', 'event_type', 'opted_in',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opted_in' => 'boolean'];
    }
}
