<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Retirement\PlanRetirementService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The scheduled plan-retirement pass (ADR-0016). Two idempotent, deny-by-default steps:
 *
 *  1. **Remind** every affected subscriber within the reminder lead window of a retiring
 *     plan's cutoff — once per subscription per retirement window.
 *  2. **Migrate** every subscription whose renewal is due on/after a retiring plan's cutoff,
 *     running the engine `RetirementRenewalPolicy`: enact the subscriber's scheduled
 *     successor / scheduled cancel / the plan's default successor, and refuse + flag an
 *     `unresolved` retirement (never charging a retired plan) for ops.
 *
 * Both steps are recorded to `plan_retirement_events`, so a re-run sends no duplicate
 * reminder and re-enacts no already-migrated subscription. `--org=` limits the run.
 */
class MigrateRetiringPlans extends Command
{
    protected $signature = 'billing:migrate-retiring-plans {--org= : Limit the run to one organization id}';

    protected $description = 'Remind affected subscribers and migrate subscriptions off retiring plans at renewal (ADR-0016).';

    public function handle(PlanRetirementService $retirements, Config $config): int
    {
        $org = $this->option('org');
        $org = is_string($org) && $org !== '' ? $org : null;

        // 1. Reminders ahead of the cutoff.
        $leadDays = $config->get('billing.retirement.reminder_lead_days', 14);
        $leadDays = is_numeric($leadDays) ? (int) $leadDays : 14;

        $reminded = $retirements->remindAffected($leadDays, org: $org);

        // 2. Migrate due subscriptions.
        $migrated = 0;
        $flagged = 0;

        foreach ($retirements->dueForMigration() as $subscription) {
            if ($org !== null && $subscription->organization_id !== $org) {
                continue;
            }

            $outcome = $retirements->migrate($subscription);

            if ($outcome === null) {
                continue;
            }

            if ($outcome === 'unresolved-retirement') {
                $flagged++;
                $this->warn(sprintf('Subscription %d flagged unresolved (org %s).', $subscription->id, $subscription->organization_id));

                continue;
            }

            $migrated++;
            $this->line(sprintf('Subscription %d migrated: %s.', $subscription->id, $outcome));
        }

        $this->info(sprintf('Reminders queued: %d · migrated: %d · flagged unresolved: %d.', $reminded, $migrated, $flagged));

        return self::SUCCESS;
    }
}
