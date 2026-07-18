<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Seats\Enums\SeatSource;
use App\Billing\Seats\SeatManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One purchased Full seat handed to a specific member (subject) of an organization. The
 * assigned rows for an org can never exceed its serving subscription's purchased seat
 * count — the {@see SeatManager} enforces that invariant on assign and
 * on release. A member in the access mirror without a row here is Light (free).
 *
 * @property int $id
 * @property string $organization_id
 * @property string $subject
 * @property SeatSource $source
 * @property Carbon|null $assigned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SeatAssignment extends Model
{
    protected $fillable = [
        'organization_id', 'subject', 'source', 'assigned_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => SeatSource::class,
            'assigned_at' => 'datetime',
        ];
    }
}
