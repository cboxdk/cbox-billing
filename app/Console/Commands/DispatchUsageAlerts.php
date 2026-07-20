<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Notifications\UsageAlertEmitter;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * The scheduled usage/overage-alert sweep (feature gap #2): for every organization with a serving
 * subscription, evaluate metered usage against the included allowance and queue an alert for any
 * newly-crossed threshold. Idempotent per (org, meter, period, threshold), so running it several
 * times a day emails each crossing exactly once.
 */
class DispatchUsageAlerts extends Command
{
    protected $signature = 'billing:usage-alerts';

    protected $description = 'Queue usage/overage alerts for orgs whose metered usage crossed an included-allowance threshold.';

    public function handle(UsageAlertEmitter $emitter): int
    {
        $fired = 0;

        // The orgs with a serving subscription (the only ones that can have a metered allowance).
        $orgIds = Subscription::query()->serving()->pluck('organization_id')->unique()->values()->all();

        Organization::query()
            ->whereIn('id', $orgIds)
            ->chunkById(200, function ($organizations) use ($emitter, &$fired): void {
                foreach ($organizations as $organization) {
                    $fired += $emitter->forOrganization($organization);
                }
            });

        $this->info(sprintf('Queued %d usage %s.', $fired, $fired === 1 ? 'alert' : 'alerts'));

        return self::SUCCESS;
    }
}
