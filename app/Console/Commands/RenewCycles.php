<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RenewSubscriptionJob;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Console\Command;

/**
 * The scheduled cycle-renewal pass (ADR-0012/0013/0014): dispatch one queued
 * {@see RenewSubscriptionJob} per active, non-paused subscription. A thin dispatcher — the
 * per-tenant renewal (grant vested allotments, advance the period, renew add-ons, issue the
 * renewal invoice, and send the ahead-of-renewal reminder) runs in the job, isolated so one
 * org's failure retries alone.
 *
 * `--org=` limits the run to one organization.
 */
class RenewCycles extends Command
{
    protected $signature = 'billing:renew {--org= : Limit the run to one organization id}';

    protected $description = 'Dispatch per-subscription cycle-renewal jobs for active subscriptions (ADR-0013/0014).';

    public function handle(): int
    {
        $query = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at');

        $org = $this->option('org');

        if (is_string($org) && $org !== '') {
            $query->where('organization_id', $org);
        }

        $dispatched = 0;

        foreach ($query->pluck('id') as $id) {
            if (! is_int($id)) {
                continue;
            }

            RenewSubscriptionJob::dispatch($id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d renewal job%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
