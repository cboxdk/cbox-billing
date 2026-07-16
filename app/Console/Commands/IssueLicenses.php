<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IssueOrgLicenseJob;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Console\Command;

/**
 * The on-prem license (re)issue pass: dispatch one queued {@see IssueOrgLicenseJob} per
 * active, non-paused subscription. A thin dispatcher — the per-tenant, idempotent issuance
 * (mint when uncovered, renew when the license predates the paid period, skip a
 * non-licensable plan, and email the key to the customer) runs in the job, isolated so one
 * org's failure retries alone.
 *
 * `--org=` limits the run to one organization.
 */
class IssueLicenses extends Command
{
    protected $signature = 'billing:issue-licenses {--org= : Limit the run to one organization id}';

    protected $description = 'Dispatch per-subscription on-prem license (re)issue jobs (idempotent).';

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

            IssueOrgLicenseJob::dispatch($id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d license job%s.', $dispatched, $dispatched === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
