<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToEnvironment;
use App\Billing\Seller\SellerCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operator-authored selling entity of record. Its `id` mirrors the config seller key so
 * the DB register and the `billing.seller.entities` config shape are interchangeable — the
 * {@see SellerCatalog} reads DB rows first and falls back to config. The
 * entity that issues an invoice drives the tax outcome, so this carries the establishment,
 * default currency, invoice-number prefix and the per-jurisdiction tax registrations.
 *
 * Transactional-email branding (additive, all nullable) rides on the same entity: the
 * customer-facing brand the lifecycle emails wrap around — header logo, accent colour,
 * validated from-name/-email + reply-to, footer legal address, support/social links, and the
 * entity's own default email locale. An entity with none set falls back to the app defaults
 * ({@see App\Billing\Notifications\Branding\BrandingResolver}).
 *
 * @property string $id
 * @property string $legal_name
 * @property string $registration_number
 * @property string $establishment
 * @property string $currency
 * @property string $invoice_prefix
 * @property bool $is_default
 * @property Carbon|null $archived_at
 * @property string|null $brand_color
 * @property string|null $logo_url
 * @property string|null $from_name
 * @property string|null $from_email
 * @property string|null $reply_to
 * @property string|null $footer_address
 * @property string|null $support_url
 * @property string|null $support_email
 * @property string|null $default_locale
 */
class SellerEntity extends Model
{
    use BelongsToEnvironment;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'legal_name', 'registration_number', 'establishment', 'currency', 'invoice_prefix', 'is_default', 'archived_at',
        'brand_color', 'logo_url', 'from_name', 'from_email', 'reply_to', 'footer_address', 'support_url', 'support_email', 'default_locale',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return HasMany<SellerTaxRegistration, $this> */
    public function taxRegistrations(): HasMany
    {
        return $this->hasMany(SellerTaxRegistration::class);
    }

    /**
     * States this entity has operator-declared physical presence in (office, staff,
     * inventory) — a nexus trigger independent of sales, each with an optional window.
     *
     * @return HasMany<SellerPhysicalPresence, $this>
     */
    public function physicalPresence(): HasMany
    {
        return $this->hasMany(SellerPhysicalPresence::class);
    }

    /**
     * Operator-declared sales into US states through OTHER channels (marketplaces,
     * other systems) — counted toward economic-nexus thresholds alongside our invoices.
     *
     * @return HasMany<SellerExternalSales, $this>
     */
    public function externalSales(): HasMany
    {
        return $this->hasMany(SellerExternalSales::class);
    }
}
