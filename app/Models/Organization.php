<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * `environment_key` is the Cbox ID environment the org lives in — the plane billing groups
 * it under (additive, nullable; single-environment deployments backfill it to the default).
 * The org id stays the tenant PK: an org belongs to exactly one environment, so its id is
 * already unique — there is no composite key. `suspended_at` reflects a Cbox ID org
 * suspension (kept fresh by the provisioning webhooks); null = active.
 *
 * `locale` is the customer's preferred language for transactional email — the top layer of
 * the locale resolution chain (customer → seller default → app fallback); nullable, so an
 * org with none falls through rather than dead-ending.
 *
 * @property string $id
 * @property string $name
 * @property string|null $environment_key
 * @property string|null $billing_email
 * @property string|null $locale
 * @property string|null $billing_currency
 * @property string|null $billing_country
 * @property string|null $billing_subdivision
 * @property string|null $tax_id
 * @property bool $tax_id_validated
 * @property Carbon|null $suspended_at
 * @property Carbon|null $erased_at
 * @property string|null $erased_by_sub
 */
class Organization extends Model
{
    use BelongsToMode;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'environment_key', 'billing_email', 'locale', 'billing_currency',
        'billing_country', 'billing_subdivision', 'tax_id', 'tax_id_validated',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tax_id_validated' => 'boolean',
            'suspended_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    /** Whether this org is currently suspended in Cbox ID (access held, not billed). */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Whether this org's PII has been erased under a right-to-be-forgotten request. Its
     * financial records are still retained (de-identified) — erasure never hard-deletes them.
     */
    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    /** Whether this org has enough of an address to resolve tax. */
    public function hasBillingAddress(): bool
    {
        return $this->billing_country !== null;
    }

    /**
     * The billing-contact email transactional mail is sent to, or null when the account
     * has none on file (the notifier skips — and logs — rather than inventing a recipient).
     */
    public function billingContact(): ?string
    {
        return $this->billing_email !== null && $this->billing_email !== '' ? $this->billing_email : null;
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

    /** @return HasMany<TaxExemptionCertificate, $this> */
    public function taxExemptionCertificates(): HasMany
    {
        return $this->hasMany(TaxExemptionCertificate::class);
    }

    /**
     * The organization's single serving subscription, if any — resolved through the
     * canonical {@see Subscription::scopeServing()} seam so it matches exactly the
     * subscription enforcement entitles (trialing/past-due/non-renewing included,
     * paused excluded), never a narrower `status = active` slice.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->serving()
            ->latest('current_period_start')
            ->first();
    }
}
