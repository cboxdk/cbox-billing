<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

/**
 * Derives the console's display standing for a subscription. Now that the engine models
 * the full lifecycle (App-A), the standing is read primarily from the real engine status
 * and the app-layer pause marker, not inferred:
 *
 *  - paused       — an app-layer pause is in effect (`paused_at`); billing is suspended.
 *  - canceled     — the engine status is Canceled (terminal).
 *  - trialing     — the engine status is Trialing (serving, pre-charge).
 *  - past_due     — the engine status is PastDue (a failed charge under smart-retry), or —
 *                   defensively — an Active account carrying an open invoice past its due date.
 *  - non_renewing — a cancellation is scheduled for period end (engine NonRenewing, or the
 *                   `cancel_at_period_end` flag on an otherwise-active subscription).
 *  - active       — an active subscription in good standing.
 */
class SubscriptionStanding
{
    public const ACTIVE = 'active';

    public const TRIALING = 'trialing';

    public const PAST_DUE = 'past_due';

    public const PAUSED = 'paused';

    public const NON_RENEWING = 'non_renewing';

    public const CANCELED = 'canceled';

    /**
     * @return 'active'|'trialing'|'past_due'|'paused'|'non_renewing'|'canceled'
     */
    public static function of(Subscription $subscription): string
    {
        if ($subscription->isPaused()) {
            return self::PAUSED;
        }

        switch ($subscription->status) {
            case SubscriptionStatus::Canceled:
                return self::CANCELED;
            case SubscriptionStatus::Trialing:
                return self::TRIALING;
            case SubscriptionStatus::PastDue:
                return self::PAST_DUE;
            case SubscriptionStatus::NonRenewing:
                return self::NON_RENEWING;
            default:
                break;
        }

        // Active: a scheduled period-end cancellation reads as non-renewing.
        if ($subscription->cancel_at_period_end) {
            return self::NON_RENEWING;
        }

        // Defensive: an Active subscription whose org carries an overdue open invoice is
        // effectively past due even if the status has not been transitioned yet.
        if (self::hasOverdueInvoice($subscription)) {
            return self::PAST_DUE;
        }

        return self::ACTIVE;
    }

    /** Whether the subscription's organization carries an open invoice past its due date. */
    private static function hasOverdueInvoice(Subscription $subscription): bool
    {
        $organization = $subscription->organization;

        if ($organization === null) {
            return false;
        }

        return $organization->invoices->contains(static fn (Invoice $invoice): bool => $invoice->status === 'open'
            && $invoice->due_at !== null
            && $invoice->due_at->isPast());
    }

    /**
     * Console counts by display standing, over every subscription.
     *
     * @return array{active: int, trialing: int, past_due: int, paused: int, non_renewing: int, canceled: int, all: int}
     */
    public static function counts(): array
    {
        $tally = ['active' => 0, 'trialing' => 0, 'past_due' => 0, 'paused' => 0, 'non_renewing' => 0, 'canceled' => 0];

        foreach (Subscription::query()->with('organization.invoices')->get() as $subscription) {
            $tally[self::of($subscription)]++;
        }

        return [
            'active' => $tally['active'],
            'trialing' => $tally['trialing'],
            'past_due' => $tally['past_due'],
            'paused' => $tally['paused'],
            'non_renewing' => $tally['non_renewing'],
            'canceled' => $tally['canceled'],
            'all' => array_sum($tally),
        ];
    }
}
