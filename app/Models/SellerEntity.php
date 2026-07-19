<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $id
 * @property string $legal_name
 * @property string $registration_number
 * @property string $establishment
 * @property string $currency
 * @property string $invoice_prefix
 * @property bool $is_default
 * @property Carbon|null $archived_at
 */
class SellerEntity extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'legal_name', 'registration_number', 'establishment', 'currency', 'invoice_prefix', 'is_default', 'archived_at',
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
}
