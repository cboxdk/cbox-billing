<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Meter;
use App\Models\Subscription;
use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Reconciliation\Contracts\Reconciler;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;
use Illuminate\Console\Command;

/**
 * Drives the engine's convergent {@see Reconciler} over every active `(org, meter)` entity
 * — the scheduled counterpart to the engine's own `billing:reconcile --target=` (which
 * takes explicit pairs). It discovers the targets from active subscriptions × their
 * entitled meters, then hands the whole batch to the contract; all the delta arithmetic
 * and the checkpoint advance live in the reconciler, so this is a thin adapter.
 */
class ReconcileActiveUsage extends Command
{
    protected $signature = 'billing:reconcile-active';

    protected $description = 'Reconcile durable usage into the ledger for every active (org, meter) entity (ADR-0003).';

    public function handle(Reconciler $reconciler, MeterPolicyResolver $policies): int
    {
        $targets = $this->targets($policies);

        if ($targets === []) {
            $this->info('No active (org, meter) entities to reconcile.');

            return self::SUCCESS;
        }

        $report = $reconciler->reconcile($targets);

        foreach ($report->reconciled as $entry) {
            $this->line(sprintf(
                '<info>%s/%s</info> meter %+d aged_out %+d',
                $entry->target->org,
                $entry->target->meter,
                $entry->meterDelta,
                $entry->agedDelta,
            ));
        }

        foreach ($report->skipped as $failure) {
            $this->error(sprintf('%s/%s skipped: %s', $failure->target->org, $failure->target->meter, $failure->error->getMessage()));
        }

        $this->info(sprintf('Reconciled %d entit%s, %d skipped.', count($report->reconciled), count($report->reconciled) === 1 ? 'y' : 'ies', count($report->skipped)));

        return $report->skipped === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Active subscriptions × the meters their plan actually entitles (deny-by-default:
     * a meter with no resolved policy is not a reconcile target).
     *
     * @return list<ReconcileTarget>
     */
    private function targets(MeterPolicyResolver $policies): array
    {
        $meters = Meter::query()->orderBy('key')->pluck('key')->all();
        $targets = [];

        $orgs = Subscription::query()
            ->where('status', 'active')
            ->pluck('organization_id')
            ->unique();

        foreach ($orgs as $org) {
            if (! is_string($org)) {
                continue;
            }

            foreach ($meters as $meter) {
                if (is_string($meter) && $policies->resolve($org, $meter) !== null) {
                    $targets[] = new ReconcileTarget($org, $meter);
                }
            }
        }

        return $targets;
    }
}
