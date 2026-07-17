<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Payments\Contracts\RetriesPayments;
use App\Billing\Subscriptions\CycleRenewalService;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Runs one subscription's cycle renewal (grant vested allotments, advance the period, renew
 * add-ons, invoice the new period) via the {@see CycleRenewalService}, and — ahead of that —
 * sends the renewal reminder on the day the term crosses into the reminder lead window. The
 * per-tenant unit the `billing:renew` pass dispatches, so one org's failure retries alone.
 */
class RenewSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $subscriptionId) {}

    public function handle(CycleRenewalService $renewals, NotifiesCustomers $notifier, Config $config, RetriesPayments $retries): void
    {
        $subscription = Subscription::query()
            ->with(['organization', 'plan.prices', 'plan.product', 'plan.creditGrants', 'plan.entitlements.meter', 'addOns'])
            ->find($this->subscriptionId);

        if (! $subscription instanceof Subscription
            || $subscription->status !== SubscriptionStatus::Active
            || $subscription->isPaused()) {
            return;
        }

        $this->maybeRemind($subscription, $notifier, $config);

        $outcome = $renewals->renew($subscription);

        // Collect the freshly-issued renewal invoice through the gateway. A hard decline
        // enters the smart-retry (PastDue) flow; an out-of-band gateway simply reports
        // pending and the settlement webhook resolves it later.
        if ($outcome->invoice !== null) {
            $retries->chargeRenewal($outcome->invoice, $subscription->refresh()->loadMissing('organization', 'plan'));
        }
    }

    /**
     * Fire the renewal reminder once, on the day the period end first falls within the
     * configured lead window — and never for a subscription already set to cancel.
     */
    private function maybeRemind(Subscription $subscription, NotifiesCustomers $notifier, Config $config): void
    {
        if ($subscription->cancel_at_period_end) {
            return;
        }

        $end = $subscription->current_period_end;

        if ($end === null) {
            return;
        }

        $lead = $config->get('billing.renewal.reminder_lead_days', 7);
        $lead = is_numeric($lead) ? (int) $lead : 7;

        $now = Carbon::now();
        $windowOpens = $now->copy()->addDays(max(0, $lead - 1));
        $windowCloses = $now->copy()->addDays($lead);

        // The single-day slice [now+lead-1, now+lead]: on a daily cadence the reminder fires
        // exactly once as the term enters the window, not on every day within it.
        if ($end->greaterThan($windowOpens) && $end->lessThanOrEqualTo($windowCloses)) {
            $notifier->renewalReminder($subscription);
        }
    }
}
