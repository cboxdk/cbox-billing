<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An org-level override of one {@see Feature}, authored from the customer detail page. The
 * override WINS over the plan resolution: `granted = true` turns the feature on for this org even
 * when its plan doesn't grant it; `granted = false` turns it off even when the plan does. At most
 * one override row per `(organization, feature)`; removing the row restores the plan-resolved
 * answer. Every write is audit-logged (a per-customer grant/revoke is an operator action).
 *
 * `value` is the config value the override forces (config features only); when granting it falls
 * back to the plan's value if null, and it is irrelevant when revoking.
 *
 * @property int $id
 * @property string $organization_id
 * @property int $feature_id
 * @property bool $granted
 * @property string|null $value
 * @property string|null $reason
 */
class OrganizationFeatureOverride extends Model
{
    protected $fillable = ['organization_id', 'feature_id', 'granted', 'value', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
