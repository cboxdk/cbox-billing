<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A metered dimension in the catalog. `key` is the stable handle the metering
 * enforcer resolves policy for (the same `meter` carried on a usage event).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $unit
 */
class Meter extends Model
{
    protected $fillable = ['key', 'name', 'unit'];

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }
}
