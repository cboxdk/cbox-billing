<?php

declare(strict_types=1);

namespace App\Billing\Webhooks\Events;

use App\Billing\Subscriptions\SubscriptionService;
use App\Models\Subscription;

/**
 * A subscription was opened. The billing engine models plan-change / renew / cancel as domain
 * events but not the initial subscribe, so the app raises this first-party event at the real
 * trigger — {@see SubscriptionService::open()} — to feed the
 * `subscription.created` outbound webhook. Not a fake: it fires exactly once, when the durable
 * subscription row is created.
 */
readonly class SubscriptionCreated
{
    public function __construct(public Subscription $subscription) {}
}
