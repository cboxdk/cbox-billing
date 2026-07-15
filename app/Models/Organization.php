<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billing organization (tenant). Its primary key is the cbox-id organization
 * identifier — the same `org` handle used across the billing engine's metering,
 * reconciliation and standing surfaces — so identity is never duplicated.
 *
 * `billing_country` / `billing_subdivision` are the org's place of supply for tax. An
 * org without a country is invoiced tax-pending (net prices, honest reason) rather
 * than with a fabricated rate.
 *
 * `billing_currency` is the currency the account chose at signup — nullable until
 * chosen. It is one-way pinned by the engine's currency lock on the first finalized
 * invoice; the lock table is the authority thereafter.
 *
 * @property string $id
 * @property string $name
 * @property string|null $billing_email
 * @property string|null $billing_currency
 * @property string|null $billing_country
 * @property string|null $billing_subdivision
 * @property string|null $tax_id
 * @property bool $tax_id_validated
 */
class Organization extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'billing_email', 'billing_currency',
        'billing_country', 'billing_subdivision', 'tax_id', 'tax_id_validated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_id_validated' => 'boolean',
        ];
    }

    /** Whether this org has enough of an address to resolve tax. */
    public function hasBillingAddress(): bool
    {
        return $this->billing_country !== null;
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** The organization's single active subscription, if any. */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->latest('current_period_start')
            ->first();
    }
}
