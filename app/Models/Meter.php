<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Metering\Enums\Aggregation;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A metered dimension in the catalog. `key` is the stable handle the metering
 * enforcer resolves policy for (the same `meter` carried on a usage event).
 *
 * `aggregation` is how the meter's raw usage events collapse into ONE billable quantity
 * per period (the engine {@see Aggregation}); it flows through
 * {@see PlanEntitlement::toMeterPolicy()} into the resolved {@see MeterPolicy}
 * so the console-authored choice is the one the engine bills with. A referenced meter is
 * archived (`archived_at`), never hard-deleted, so its historical policy keeps resolving.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $unit
 * @property Aggregation $aggregation
 * @property string|null $display
 * @property Carbon|null $archived_at
 */
class Meter extends Model
{
    protected $fillable = ['key', 'name', 'unit', 'aggregation', 'display', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'aggregation' => Aggregation::class,
            'archived_at' => 'datetime',
        ];
    }

    /** The human label to show for this meter — its display label, else its name. */
    public function label(): string
    {
        return $this->display !== null && $this->display !== '' ? $this->display : $this->name;
    }

    /** Whether the meter has been archived (soft-deactivated). */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Only live (non-archived) meters.
     *
     * @param  Builder<Meter>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return HasMany<PlanEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }
}
