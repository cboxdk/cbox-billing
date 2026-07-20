<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operator-authored, embeddable pricing table (#57) — the public, branded storefront a
 * marketing site drops in. It is a pure projection layer over the catalog: it names which
 * {@see Plan} columns to show (via {@see PricingTablePlan}), which {@see Feature}s to compare
 * across them (via {@see PricingTableFeature}), which currencies it may present, and how the
 * per-plan CTA deep-links into checkout. It owns no money and grants nothing — the plans,
 * prices, entitlements and feature grants are the catalog truth.
 *
 * `key` addresses the no-auth `/pricing/{key}` page; an inactive table 404s there (it stays
 * editable in the console). Branding is the {@see SellerEntity}'s, resolved through the same
 * {@see App\Billing\Notifications\Branding\BrandingResolver} the emails use.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $seller_entity_id
 * @property list<string>|null $currencies
 * @property string|null $default_currency
 * @property bool $interval_toggle
 * @property string|null $cta_label
 * @property string|null $cta_url_template
 * @property bool $active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PricingTable extends Model
{
    use BelongsToEnvironment;

    protected $fillable = [
        'key', 'name', 'seller_entity_id', 'currencies', 'default_currency',
        'interval_toggle', 'cta_label', 'cta_url_template', 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currencies' => 'array',
            'interval_toggle' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Only active (publicly servable) tables.
     *
     * @param  Builder<PricingTable>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @return BelongsTo<SellerEntity, $this> */
    public function sellerEntity(): BelongsTo
    {
        return $this->belongsTo(SellerEntity::class, 'seller_entity_id');
    }

    /** The ordered plan columns of this table. */
    /** @return HasMany<PricingTablePlan, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(PricingTablePlan::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The ordered feature rows of the comparison matrix. */
    /** @return HasMany<PricingTableFeature, $this> */
    public function featureRows(): HasMany
    {
        return $this->hasMany(PricingTableFeature::class)->orderBy('sort_order')->orderBy('id');
    }
}
