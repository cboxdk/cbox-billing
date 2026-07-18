<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded moment of a plan's retirement being handled for a subscription (ADR-0016):
 * a `reminder` sent ahead of the cutoff, a `migrated` renewal that enacted the successor /
 * default / cancel, or an `unresolved` flag surfaced to ops (a retired plan with no choice
 * and no default — never silently charged). Append-only and idempotent per
 * `(subscription, retires_at, type)`, so a re-run of the migration pass sends no duplicate
 * reminder and records no duplicate migration.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $organization_id
 * @property int $plan_id
 * @property Carbon $retires_at
 * @property string $type
 * @property string|null $outcome
 * @property int|null $successor_plan_id
 * @property string|null $detail
 */
class PlanRetirementEvent extends Model
{
    public const TYPE_REMINDER = 'reminder';

    public const TYPE_MIGRATED = 'migrated';

    public const TYPE_UNRESOLVED = 'unresolved';

    protected $fillable = [
        'subscription_id', 'organization_id', 'plan_id', 'retires_at',
        'type', 'outcome', 'successor_plan_id', 'detail',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'successor_plan_id');
    }
}
