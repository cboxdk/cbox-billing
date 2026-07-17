<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Billing\Subscriptions\Contracts\ConvertsTrials;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Runs one trial's conversion pass: ahead of the trial end it sends the trial-ending
 * reminder as the term crosses into the configured lead window, and once the trial end has
 * passed it converts the subscription to paying (first charge) via {@see ConvertsTrials}.
 * The per-tenant unit the `billing:convert-trials` pass dispatches, so one org's failure
 * retries alone.
 */
class ConvertTrialJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $subscriptionId) {}

    public function handle(ConvertsTrials $trials, NotifiesCustomers $notifier, Config $config): void
    {
        $subscription = Subscription::query()
            ->with(['organization', 'plan.prices', 'plan.product'])
            ->find($this->subscriptionId);

        if (! $subscription instanceof Subscription || ! $subscription->isTrialing() || $subscription->isPaused()) {
            return;
        }

        $this->maybeRemind($subscription, $notifier, $config);

        $trials->convertDue($subscription);
    }

    /**
     * Fire the trial-ending reminder once, on the day the trial end first falls within the
     * configured lead window (and only while the trial is still in the future).
     */
    private function maybeRemind(Subscription $subscription, NotifiesCustomers $notifier, Config $config): void
    {
        $endsAt = $subscription->trial_ends_at;

        if ($endsAt === null) {
            return;
        }

        $lead = $config->get('billing.trial.reminder_lead_days', 3);
        $lead = is_numeric($lead) ? (int) $lead : 3;

        $now = Carbon::now();
        $windowOpens = $now->copy()->addDays(max(0, $lead - 1));
        $windowCloses = $now->copy()->addDays($lead);

        // The single-day slice [now+lead-1, now+lead]: on a daily cadence the reminder fires
        // exactly once as the trial enters the window, not on every day within it.
        if ($endsAt->greaterThan($windowOpens) && $endsAt->lessThanOrEqualTo($windowCloses)) {
            $notifier->trialEnding($subscription, $endsAt->toDateTimeImmutable());
        }
    }
}
