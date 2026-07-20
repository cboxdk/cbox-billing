<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Features\FeatureEntitlements;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's grant of one boolean/config {@see Feature} — the durable source the
 * {@see FeatureEntitlements} resolver reads a feature grant from. The
 * boolean/config peer of {@see PlanEntitlement} (which is the metered peer). At most one row per
 * `(plan, feature)`; deny-by-default lives one layer up — no row means the plan doesn't grant it.
 *
 * `value` is the config value the plan grants, stored as a string and typed on resolution by the
 * feature's {@see Feature::$value_type} (null for a boolean feature).
 *
 * @property int $id
 * @property int $plan_id
 * @property int $feature_id
 * @property bool $enabled
 * @property string|null $value
 */
class PlanFeature extends Model
{
    protected $fillable = ['plan_id', 'feature_id', 'enabled', 'value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
