<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReconcileOrgUsageJob;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * The reconciliation pass (ADR-0003): dispatch one queued {@see ReconcileOrgUsageJob} per
 * organization with an active subscription. A thin dispatcher — the per-tenant convergence
 * (discover the org's entitled meters and post the cumulative deltas into the ledger) runs
 * in the job, isolated so a store/ledger hiccup for one org retries alone instead of failing
 * the whole pass.
 */
class ReconcileActiveUsage extends Command
{
    protected $signature = 'billing:reconcile-active';

    protected $description = 'Dispatch per-organization usage-reconciliation jobs (ADR-0003).';

    public function handle(): int
    {
        $orgs = Subscription::query()
            ->where('status', 'active')
            ->pluck('organization_id')
            ->unique();

        $dispatched = 0;

        foreach ($orgs as $org) {
            if (is_string($org)) {
                ReconcileOrgUsageJob::dispatch($org);
                $dispatched++;
            }
        }

        $this->info(sprintf('Dispatched reconciliation for %d organization%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
