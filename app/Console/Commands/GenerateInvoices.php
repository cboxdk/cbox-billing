<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IssueSubscriptionInvoiceJob;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * The invoicing pass: dispatch one queued {@see IssueSubscriptionInvoiceJob} per active
 * subscription. A thin dispatcher — the per-tenant invoicing (catalog price → tax → legal
 * numbering → app rows → customer email) runs in the job, isolated so one org's failure
 * retries on its own without stalling the batch.
 *
 * `--org=` limits the run to one organization.
 */
class GenerateInvoices extends Command
{
    protected $signature = 'billing:invoice {--org= : Limit the run to one organization id}';

    protected $description = 'Dispatch per-subscription invoicing jobs for active subscriptions.';

    public function handle(): int
    {
        $query = Subscription::query()->where('status', 'active');

        $org = $this->option('org');

        if (is_string($org) && $org !== '') {
            $query->where('organization_id', $org);
        }

        $dispatched = 0;

        foreach ($query->pluck('id') as $id) {
            if (! is_int($id)) {
                continue;
            }

            IssueSubscriptionInvoiceJob::dispatch($id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d invoicing job%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
