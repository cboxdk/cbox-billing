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
 * @property string $id
 * @property string $name
 * @property string|null $billing_email
 */
class Organization extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'name', 'billing_email'];

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
