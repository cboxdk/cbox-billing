<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Model;

/**
 * The idempotency record that a usage/overage alert already fired for an
 * (organization, meter, billing period, threshold). The unique key on the table makes the
 * emitter fire each crossing exactly once per period; this model is the plane-scoped accessor.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $meter_key
 * @property string $period_key
 * @property int $threshold
 * @property bool $livemode
 */
class UsageAlertDispatch extends Model
{
    use BelongsToMode;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'meter_key', 'period_key', 'threshold',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'livemode' => 'boolean',
        ];
    }
}
