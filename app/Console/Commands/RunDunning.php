<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Dunning\Contracts\DelinquencyPolicy;
use Cbox\Billing\Payment\Dunning\DunningRunner;
use Cbox\Billing\Payment\Dunning\Enums\InvoicePaymentState;
use Cbox\Billing\Payment\Dunning\ValueObjects\DelinquentInvoice;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Drives the engine's {@see DunningRunner} for every organization against its current
 * invoices: it assembles each account's {@see DelinquentInvoice} snapshot from the app's
 * invoice rows, lets the pure {@see DelinquencyPolicy}
 * decide the single action (notice / suspend / restore / nothing), and the runner applies
 * it — flipping the durable {@see AccountStanding} on
 * suspend/restore. Suspension gates ACCESS only; it never touches credits or the ledger.
 */
class RunDunning extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Evaluate and apply the delinquency policy for every organization (access-gating only).';

    public function handle(DunningRunner $runner): int
    {
        $now = new DateTimeImmutable;
        $organizations = Organization::query()->orderBy('id')->get();

        foreach ($organizations as $organization) {
            $outcome = $runner->run($organization->id, $this->invoices($organization), $now);

            $this->line(sprintf(
                '<info>%s</info> → %s (%s)',
                $organization->id,
                $outcome->action->value,
                $outcome->reason,
            ));
        }

        $this->info(sprintf('Dunning evaluated for %d organization%s.', $organizations->count(), $organizations->count() === 1 ? '' : 's'));

        return self::SUCCESS;
    }

    /**
     * The account's issued invoices as dunning sees them.
     *
     * @return list<DelinquentInvoice>
     */
    private function invoices(Organization $organization): array
    {
        $invoices = [];

        $rows = Invoice::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('due_at')
            ->get();

        foreach ($rows as $invoice) {
            $dueAt = $invoice->due_at;

            if (! $dueAt instanceof Carbon) {
                continue;
            }

            $invoices[] = new DelinquentInvoice(
                number: $invoice->number,
                dueAt: $dueAt->toDateTimeImmutable(),
                state: $this->state($invoice->status),
                amountDue: $invoice->isPaid()
                    ? Money::zero($invoice->currency)
                    : Money::ofMinor($invoice->total_minor, $invoice->currency),
            );
        }

        return $invoices;
    }

    private function state(string $status): InvoicePaymentState
    {
        return match ($status) {
            'paid' => InvoicePaymentState::Paid,
            'uncollectible', 'void' => InvoicePaymentState::Uncollectible,
            default => InvoicePaymentState::Open,
        };
    }
}
