<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Events;

use App\Billing\Subscriptions\SubscriptionService;
use App\Models\Subscription;

/**
 * A subscription was canceled immediately (the durable row is stamped `Canceled`). Raised by
 * {@see SubscriptionService::cancel()} on the immediate path only — a
 * cancel-at-period-end is a scheduled state, not a cancellation, and does not fire this. Distinct
 * from the engine's `subscription.cancellation_requested` signal, which precedes any state change.
 */
readonly class SubscriptionCanceled
{
    public function __construct(public Subscription $subscription) {}
}
