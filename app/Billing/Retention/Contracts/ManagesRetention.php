<?php

declare(strict_types=1);

namespace App\Billing\Retention\Contracts;

use App\Billing\Retention\Exceptions\RetentionException;
use App\Billing\Retention\ValueObjects\CancellationRequest;
use App\Models\Subscription;

/**
 * The retention surface (Part 2): cancellation-with-reason (immediate, scheduled for period
 * end, or a pause-instead-of-cancel save) and win-back reactivation (resume a paused
 * subscription, undo a scheduled cancel, or re-activate one canceled within the win-back
 * window). Every action captures its reason for churn analytics. Controllers depend on this
 * contract, never the concrete service.
 */
interface ManagesRetention
{
    /**
     * Enact `$request` against `$subscription`: persist the captured reason, then apply the
     * chosen mode — immediate cancel, cancel-at-period-end, or pause. Returns the updated
     * subscription.
     */
    public function cancel(Subscription $subscription, CancellationRequest $request): Subscription;

    /**
     * Win back `$subscription`: resume it if paused, undo a scheduled period-end cancel, or
     * re-subscribe it if it was canceled within the configured win-back window. Records the
     * reactivation. Throws {@see RetentionException} when
     * the subscription is in none of those reactivatable states.
     */
    public function reactivate(Subscription $subscription): Subscription;
}
