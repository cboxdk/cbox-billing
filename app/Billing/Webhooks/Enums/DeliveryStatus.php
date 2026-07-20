<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Enums;

use App\Models\WebhookDelivery;

/**
 * The lifecycle of a single {@see WebhookDelivery} attempt-set.
 *
 *  - {@see Pending}   — created, not yet attempted (or queued for its first attempt).
 *  - {@see Delivered} — a `2xx` was returned; terminal success.
 *  - {@see Failed}    — an attempt failed and a retry is scheduled (`next_retry_at` set).
 *  - {@see Dead}      — the retry budget is exhausted; terminal failure (dead-letter).
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Dead = 'dead';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed (retrying)',
            self::Dead => 'Dead',
        };
    }

    /** A delivery an operator may manually re-attempt. */
    public function isRedeliverable(): bool
    {
        return $this === self::Failed || $this === self::Dead;
    }
}
