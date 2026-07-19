<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Models\Invoice;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

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
     * Console counts by display standing, over every subscription — a single indexed
     * `GROUP BY` over the materialized {@see $display_standing} column (PERF-3), not a
     * whole-table load and PHP tally.
     *
     * @return array{active: int, trialing: int, past_due: int, paused: int, non_renewing: int, canceled: int, all: int}
     */
    public static function counts(): array
    {
        $tally = ['active' => 0, 'trialing' => 0, 'past_due' => 0, 'paused' => 0, 'non_renewing' => 0, 'canceled' => 0];

        $rows = Subscription::query()
            ->toBase()
            ->selectRaw('display_standing, count(*) as aggregate')
            ->groupBy('display_standing')
            ->pluck('aggregate', 'display_standing');

        foreach ($tally as $standing => $_) {
            $count = $rows->get($standing);
            $tally[$standing] = is_numeric($count) ? (int) $count : 0;
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

    /**
     * Recompute and persist one subscription's materialized {@see $display_standing} (PERF-3).
     * The stored value is derived through {@see of()} against the org's invoices, so it equals
     * the live computation by construction. Written with the base query builder — no model
     * event, no timestamp bump — so the maintaining observer never recurses.
     */
    public static function refreshFor(Subscription $subscription): void
    {
        $subscription->loadMissing('organization.invoices');

        $standing = self::of($subscription);

        DB::table('subscriptions')->where('id', $subscription->getKey())->update(['display_standing' => $standing]);

        // Keep the in-memory instance consistent with what was just persisted.
        $subscription->setAttribute('display_standing', $standing);
        $subscription->syncOriginalAttribute('display_standing');
    }

    /**
     * Recompute the standing for every subscription of an organization (PERF-3). Used when an
     * INVOICE changes — the overdue-open-invoice fallback is org-scoped, so any of the org's
     * subscriptions may change standing even though the subscription rows themselves did not.
     */
    public static function refreshForOrg(string $organizationId): void
    {
        Subscription::query()
            ->where('organization_id', $organizationId)
            ->with('organization.invoices')
            ->get()
            ->each(static function (Subscription $subscription): void {
                self::refreshFor($subscription);
            });
    }

    /** Recompute every subscription's standing (the daily catch-up for due dates that pass). */
    public static function refreshAll(): void
    {
        Subscription::query()->with('organization.invoices')
            ->chunkById(200, static function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    self::refreshFor($subscription);
                }
            });
    }
}
