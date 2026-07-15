<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;

/**
 * Derives the console's display standing for a subscription from real data. The engine
 * models only {@see SubscriptionStatus::Active} / {@see SubscriptionStatus::Canceled};
 * the finer console labels are *observed*, never stored:
 *
 *  - canceled  — the engine status is Canceled.
 *  - past_due  — the org carries an open invoice already past its due date.
 *  - trialing  — an active subscription that has never been invoiced (in trial, pre-charge).
 *  - active    — an active, invoiced subscription in good standing.
 */
class SubscriptionStanding
{
    public const ACTIVE = 'active';

    public const TRIALING = 'trialing';

    public const PAST_DUE = 'past_due';

    public const CANCELED = 'canceled';

    /**
     * @return 'active'|'trialing'|'past_due'|'canceled'
     */
    public static function of(Subscription $subscription): string
    {
        if ($subscription->status === SubscriptionStatus::Canceled) {
            return self::CANCELED;
        }

        $organization = $subscription->organization;

        if ($organization === null) {
            return self::ACTIVE;
        }

        $invoices = $organization->invoices;

        $pastDue = $invoices->first(static fn (Invoice $invoice): bool => $invoice->status === 'open'
            && $invoice->due_at !== null
            && $invoice->due_at->isPast());

        if ($pastDue instanceof Invoice) {
            return self::PAST_DUE;
        }

        if ($invoices->isEmpty()) {
            return self::TRIALING;
        }

        return self::ACTIVE;
    }

    /**
     * Console counts by display standing, over every subscription.
     *
     * @return array{active: int, trialing: int, past_due: int, canceled: int, all: int}
     */
    public static function counts(): array
    {
        $tally = ['active' => 0, 'trialing' => 0, 'past_due' => 0, 'canceled' => 0];

        foreach (Subscription::query()->with('organization.invoices')->get() as $subscription) {
            $tally[self::of($subscription)]++;
        }

        return [
            'active' => $tally['active'],
            'trialing' => $tally['trialing'],
            'past_due' => $tally['past_due'],
            'canceled' => $tally['canceled'],
            'all' => array_sum($tally),
        ];
    }
}
