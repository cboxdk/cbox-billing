<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunOrgDunningJob;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * The dunning pass: dispatch one queued {@see RunOrgDunningJob} per organization. A thin
 * dispatcher — the per-tenant work (assemble the account's delinquent-invoice snapshot, let
 * the engine's delinquency policy decide the single action, apply it, and notify the
 * customer on a notice/suspension) runs in the job, isolated so one org's failure retries on
 * its own. Suspension gates ACCESS only; it never touches credits or the ledger.
 */
class RunDunning extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Dispatch per-organization dunning jobs (access-gating only).';

    public function handle(): int
    {
        $dispatched = 0;

        foreach (Organization::query()->orderBy('id')->pluck('id') as $id) {
            if (is_string($id)) {
                RunOrgDunningJob::dispatch($id);
                $dispatched++;
            }
        }

        $this->info(sprintf('Dispatched dunning for %d organization%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
