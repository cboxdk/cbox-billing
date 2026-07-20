<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Hosted\Enums\SessionStatus;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hosted checkout- or customer-portal session (ADR-0009 Path A). It is addressed by an
 * opaque `token` in the hosted page URL — the token, not the provider auth gate,
 * authorizes the page. A checkout carries the `plan_key` (and optional signup `currency`)
 * it collects payment for; the `payment_reference` is the reference the gateway's settled
 * webhook carries, joining the client-side intent to the exactly-once activation.
 *
 * @property string $id
 * @property string $token
 * @property string $organization_id
 * @property SessionType $type
 * @property string|null $plan_key
 * @property string|null $currency
 * @property string|null $coupon_code
 * @property string $return_url
 * @property string|null $payment_reference
 * @property SessionStatus $status
 * @property bool $livemode
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 */
class BillingSession extends Model
{
    use BelongsToMode;
    use HasUuids;

    protected $fillable = [
        'token', 'organization_id', 'type', 'plan_key', 'currency', 'coupon_code',
        'return_url', 'payment_reference', 'status', 'expires_at', 'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SessionType::class,
            'status' => SessionStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Whether the session's TTL has elapsed. */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Whether a checkout has been activated (its settled webhook applied). */
    public function isComplete(): bool
    {
        return $this->status === SessionStatus::Complete;
    }

    /**
     * Whether the token still authorizes its page: pending and within its TTL. A complete
     * portal session stays usable; a complete checkout has nothing left to collect.
     */
    public function isUsable(): bool
    {
        return $this->status === SessionStatus::Pending && ! $this->isExpired();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
