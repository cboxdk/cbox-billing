<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\Invoicing\Contracts\GeneratesInvoices;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Throwable;

/**
 * The invoicing run: issue a period invoice for active subscriptions via the
 * {@see GeneratesInvoices} service (catalog price → tax → legal numbering → app rows).
 * A subscription whose org has no resolvable billing address is skipped with a tax-pending
 * notice rather than invoiced with a wrong amount.
 *
 * `--org=` limits the run to one organization; without it every active subscription is
 * invoiced.
 */
class GenerateInvoices extends Command
{
    protected $signature = 'billing:invoice {--org= : Limit the run to one organization id}';

    protected $description = 'Generate period invoices for active subscriptions (composes the tax engine).';

    public function handle(GeneratesInvoices $invoices): int
    {
        $query = Subscription::query()->where('status', 'active')->with(['organization', 'plan']);

        $org = $this->option('org');

        if (is_string($org) && $org !== '') {
            $query->where('organization_id', $org);
        }

        $subscriptions = $query->get();
        $issued = 0;
        $skipped = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $invoice = $invoices->generate($subscription);
                $issued++;
                $this->line(sprintf('<info>%s</info> invoice %s total %d %s', $subscription->organization_id, $invoice->number, $invoice->total_minor, $invoice->currency));
            } catch (Throwable $e) {
                $skipped++;
                $this->warn(sprintf('%s skipped: %s', $subscription->organization_id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Issued %d invoice%s, skipped %d.', $issued, $issued === 1 ? '' : 's', $skipped));

        return self::SUCCESS;
    }
}
