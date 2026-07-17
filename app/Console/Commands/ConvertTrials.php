<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ConvertTrialJob;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Console\Command;

/**
 * The scheduled trial-conversion pass: dispatch one queued {@see ConvertTrialJob} per
 * unpaused `Trialing` subscription. A thin dispatcher — the per-tenant work (send the
 * trial-ending reminder as the trial crosses into the lead window, and convert the trial to
 * a paying subscription once its end has passed) runs in the job, isolated so one org's
 * failure retries alone.
 */
class ConvertTrials extends Command
{
    protected $signature = 'billing:convert-trials';

    protected $description = 'Dispatch per-subscription trial jobs: send the ending reminder and convert due trials.';

    public function handle(): int
    {
        $dispatched = 0;

        $trials = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNull('paused_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($trials as $id) {
            if (! is_int($id)) {
                continue;
            }

            ConvertTrialJob::dispatch($id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d trial job%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
