<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Features\Enums\ConfigValueType;
use App\Billing\Features\Enums\FeatureType;
use App\Billing\Features\FeatureEntitlements;
use Cbox\License\Capabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A boolean / non-metered product feature in the catalog — the gating dimension the
 * {@see FeatureEntitlements} resolver answers "does this org have feature
 * X?" from. `key` is a stable slug drawn from the SAME vocabulary the on-prem license
 * `entitlements` speak ({@see Capabilities}), so a hosted subscription and a
 * self-hosted license gate on the same names (`sso`, `scim`, `platform.multi_tenant`, …).
 *
 * A boolean feature is pure on/off; a config feature carries a typed value/limit
 * (`max_projects=10`) whose type is {@see $value_type}. A referenced feature is archived
 * (`archived_at`), never hard-deleted, so a plan grant or an org override never orphans.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property FeatureType $type
 * @property ConfigValueType|null $value_type
 * @property Carbon|null $archived_at
 */
class Feature extends Model
{
    protected $fillable = ['key', 'name', 'description', 'type', 'value_type', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => FeatureType::class,
            'value_type' => ConfigValueType::class,
            'archived_at' => 'datetime',
        ];
    }

    /** Whether the feature has been archived (soft-deactivated). */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Coerce a stored string value (from a plan grant or an org override) into this feature's
     * typed value. A boolean feature carries no value, so it always resolves to null.
     */
    public function castValue(?string $value): int|string|null
    {
        if ($this->type !== FeatureType::Config || ! $this->value_type instanceof ConfigValueType) {
            return null;
        }

        return $this->value_type->cast($value);
    }

    /**
     * Only live (non-archived) features.
     *
     * @param  Builder<Feature>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @return HasMany<PlanFeature, $this> */
    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return HasMany<OrganizationFeatureOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(OrganizationFeatureOverride::class);
    }
}
