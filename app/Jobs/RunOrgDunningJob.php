<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Notifications\Contracts\NotifiesCustomers;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Dunning\DunningRunner;
use Cbox\Billing\Payment\Dunning\Enums\DunningAction;
use Cbox\Billing\Payment\Dunning\Enums\InvoicePaymentState;
use Cbox\Billing\Payment\Dunning\ValueObjects\DelinquentInvoice;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Evaluates and applies the delinquency policy for one organization (via the engine's
 * {@see DunningRunner}) and — when the decision is to send a notice or to suspend — emails
 * the account its dunning notice. The per-tenant unit the `billing:dunning` pass dispatches,
 * so one org's failure retries in isolation. Suspension gates ACCESS only; this job never
 * touches credits or the ledger.
 */
class RunOrgDunningJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** Hold the uniqueness lock for at most this many seconds if the job dies mid-run (H5). */
    public int $uniqueFor = 600;

    public function __construct(public string $organizationId) {}

    /** One in-flight dunning evaluation per organization (H5): overlapping passes are dropped. */
    public function uniqueId(): string
    {
        return 'org-dunning:'.$this->organizationId;
    }

    public function handle(DunningRunner $runner, NotifiesCustomers $notifier): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        $rows = Invoice::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('due_at')
            ->get();

        $now = new DateTimeImmutable;
        $outcome = $runner->run($organization->id, $this->snapshot($rows), $now);

        // Notify on every notice AND on suspension, so an account is never suspended
        // un-warned. Restore / no-op send nothing.
        if (in_array($outcome->action, [DunningAction::SendNotice, DunningAction::Suspend], true)) {
            $notifier->dunningNotice(
                $organization,
                $this->amountDue($rows),
                $outcome->action === DunningAction::Suspend,
                $this->oldestDueAt($rows),
            );
        }
    }

    /**
     * The account's issued invoices as dunning sees them.
     *
     * @param  Collection<int, Invoice>  $rows
     * @return list<DelinquentInvoice>
     */
    private function snapshot($rows): array
    {
        $invoices = [];

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

    /**
     * The account's total outstanding balance across its unpaid invoices, in the account
     * currency. Zero (in a best-effort currency) when nothing is open.
     *
     * @param  Collection<int, Invoice>  $rows
     */
    private function amountDue($rows): Money
    {
        $currency = 'DKK';
        $total = 0;

        foreach ($rows as $invoice) {
            if ($invoice->isPaid() || $invoice->status === 'void') {
                continue;
            }

            $currency = $invoice->currency; // Open invoices share the account currency.
            $total += $invoice->total_minor;
        }

        return Money::ofMinor($total, $currency);
    }

    /**
     * The due date of the oldest unpaid invoice, for the notice copy.
     *
     * @param  Collection<int, Invoice>  $rows
     */
    private function oldestDueAt($rows): ?DateTimeImmutable
    {
        $due = $rows
            ->filter(static fn (Invoice $invoice): bool => ! $invoice->isPaid() && $invoice->due_at instanceof Carbon)
            ->sortBy('due_at')
            ->first();

        return $due?->due_at?->toDateTimeImmutable();
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
