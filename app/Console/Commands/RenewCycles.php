<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Subscriptions\CycleRenewalService;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\SubscriptionStatus;
use Illuminate\Console\Command;
use Throwable;

/**
 * The scheduled cycle-renewal run (ADR-0012/0013/0014): fire each active subscription's
 * recurring per-cycle credit allotments, advance the period on its boundary, renew add-ons,
 * and issue the renewal invoice — a thin adapter over the {@see CycleRenewalService}, which
 * owns the idempotent, time-keyed granting.
 *
 * Every active, non-paused subscription is offered to the service each run: the service
 * grants only what has vested and only advances a period whose boundary has passed, so a
 * daily cadence drips finer-grained allotments and rolls periods over exactly once. Paused
 * and canceled subscriptions are skipped; a due end-of-period cancellation is enacted.
 *
 * `--org=` limits the run to one organization.
 */
class RenewCycles extends Command
{
    protected $signature = 'billing:renew {--org= : Limit the run to one organization id}';

    protected $description = 'Fire scheduled per-cycle credit allotments, advance periods, renew add-ons, and invoice renewals (ADR-0013/0014).';

    public function handle(CycleRenewalService $renewals): int
    {
        $query = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')
            ->with(['organization', 'plan.prices', 'plan.product', 'plan.creditGrants', 'plan.entitlements.meter', 'addOns']);

        $org = $this->option('org');

        if (is_string($org) && $org !== '') {
            $query->where('organization_id', $org);
        }

        $renewed = 0;
        $addOns = 0;
        $canceled = 0;
        $invoices = 0;
        $failed = 0;

        foreach ($query->get() as $subscription) {
            try {
                $outcome = $renewals->renew($subscription);
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('%s failed: %s', $subscription->organization_id, $e->getMessage()));

                continue;
            }

            $addOns += $outcome->addOnsRenewed;

            if ($outcome->canceled) {
                $canceled++;
                $this->line(sprintf('<comment>%s</comment> ended (cancellation came due)', $subscription->organization_id));
            }

            if ($outcome->baseRenewed) {
                $renewed++;
                $invoice = $outcome->invoice;
                $invoices += $invoice === null ? 0 : 1;
                $this->line(sprintf(
                    '<info>%s</info> renewed to %s → %s',
                    $subscription->organization_id,
                    $subscription->current_period_end?->format('Y-m-d') ?? 'n/a',
                    $invoice === null ? 'invoice pending' : $invoice->number,
                ));
            }
        }

        $this->info(sprintf(
            'Renewed %d, %d add-on cycle%s, %d canceled, %d invoice%s, %d failed.',
            $renewed,
            $addOns,
            $addOns === 1 ? '' : 's',
            $canceled,
            $invoices,
            $invoices === 1 ? '' : 's',
            $failed,
        ));

        return self::SUCCESS;
    }
}
