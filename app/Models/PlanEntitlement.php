<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's metered entitlement for one meter — the durable source a host resolves a
 * {@see MeterPolicy} from. Deny-by-default lives one layer up: no row for an
 * (org, meter) means no policy, which the enforcer refuses.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $meter_id
 * @property bool $enabled
 * @property int $allowance
 * @property float|null $multiplier
 * @property bool $unlimited
 * @property OverageBehaviour $overage
 */
class PlanEntitlement extends Model
{
    protected $fillable = [
        'plan_id', 'meter_id', 'enabled', 'allowance', 'multiplier', 'unlimited', 'overage',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allowance' => 'integer',
            'multiplier' => 'float',
            'unlimited' => 'boolean',
            'overage' => OverageBehaviour::class,
        ];
    }

    /**
     * Project this entitlement row into the engine's per-bucket policy. A disabled or
     * unlimited row maps to the matching named constructor; a costed row carries its
     * isolated allowance, per-unit weight and overage behaviour.
     */
    public function toMeterPolicy(): MeterPolicy
    {
        if (! $this->enabled) {
            return MeterPolicy::disabled();
        }

        if ($this->unlimited) {
            return MeterPolicy::unlimited();
        }

        return MeterPolicy::metered(
            allowance: $this->allowance,
            multiplier: $this->multiplier ?? 0.0,
            overage: $this->overage,
        );
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Meter, $this> */
    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }
}
