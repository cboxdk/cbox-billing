<?php

declare(strict_types=1);

namespace App\Models;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\ValueObjects\AddOn;
use Cbox\Billing\Subscription\ValueObjects\BillingCycle;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on attached to a subscription (ADR-0012): an extra recurring charge with an
 * optional per-cycle credit allotment, billed either **aligned** to the base
 * subscription's period or on its own **independent** {@see BillingCycle}. The durable
 * row projects into the engine's {@see AddOn} value object, which owns the alignment,
 * proration, and allotment math.
 *
 * @property int $id
 * @property int $subscription_id
 * @property string $key
 * @property int $price_minor
 * @property string $currency
 * @property AddOnAlignment $alignment
 * @property int $credit_allotment
 * @property int|null $anchor_day
 * @property int|null $anchor_month
 * @property BillingInterval|null $interval
 */
class SubscriptionAddOn extends Model
{
    protected $fillable = [
        'subscription_id', 'key', 'price_minor', 'currency', 'alignment',
        'credit_allotment', 'anchor_day', 'anchor_month', 'interval',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'credit_allotment' => 'integer',
            'anchor_day' => 'integer',
            'anchor_month' => 'integer',
            'alignment' => AddOnAlignment::class,
            'interval' => BillingInterval::class,
        ];
    }

    /**
     * Project this row into the engine's {@see AddOn}. An independent add-on carries its
     * own {@see BillingCycle} (its anchor day/month + interval, UTC); an aligned add-on
     * carries none and follows the base subscription's period.
     */
    public function toEngineAddOn(): AddOn
    {
        return new AddOn(
            id: $this->key,
            priceId: $this->key,
            price: Money::ofMinor($this->price_minor, $this->currency),
            alignment: $this->alignment,
            cycle: $this->cycle(),
            creditAllotment: $this->credit_allotment,
        );
    }

    private function cycle(): ?BillingCycle
    {
        if ($this->alignment !== AddOnAlignment::Independent || $this->interval === null || $this->anchor_day === null) {
            return null;
        }

        return new BillingCycle(
            anchorDay: $this->anchor_day,
            anchorMonth: $this->anchor_month ?? 1,
            interval: $this->interval,
            zone: new DateTimeZone('UTC'),
        );
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
