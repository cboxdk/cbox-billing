<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Mode\Concerns\BelongsToMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One redemption of a {@see Coupon} by an organization — the append-only ledger the
 * redeemer writes under a lock to enforce `max_redemptions` (per-coupon) and the optional
 * per-customer cap. Stamped with the subscription it was redeemed against so a coupon's
 * detail can cross-link to the discounted subscriptions.
 *
 * @property int $id
 * @property int $coupon_id
 * @property string $organization_id
 * @property int|null $subscription_id
 * @property Carbon $redeemed_at
 */
class CouponRedemption extends Model
{
    use BelongsToMode;

    protected $fillable = [
        'coupon_id', 'organization_id', 'subscription_id', 'redeemed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
