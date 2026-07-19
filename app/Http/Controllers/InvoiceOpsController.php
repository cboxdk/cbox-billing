<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Invoicing\Contracts\RunsInvoiceOperations;
use App\Billing\Invoicing\Exceptions\InvoiceActionDenied;
use App\Billing\Invoicing\ValueObjects\ManualLine;
use App\Billing\Support\MoneyFormatter;
use App\Models\Invoice;
use App\Models\Organization;
use Cbox\Billing\Refund\Enums\RefundReason;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The invoice lifecycle-actions console (Wave 3) — thin HTTP over
 * {@see RunsInvoiceOperations}. Void, refund (→ credit note through the engine refunder),
 * record a manual/offline payment, re-queue the email, and create an ad-hoc invoice. Every
 * money movement flows through the engine; every guard (`InvoiceActionDenied`) is enforced
 * server-side and flashed back — the confirm dialog is UX only.
 */
class InvoiceOpsController extends Controller
{
    public function void(Invoice $invoice, RunsInvoiceOperations $operations): RedirectResponse
    {
        try {
            $operations->void($invoice);
        } catch (InvoiceActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.invoices.show', $invoice->id)
            ->with('status', sprintf('Invoice %s voided.', $invoice->number));
    }

    public function refund(Request $request, Invoice $invoice, RunsInvoiceOperations $operations, RecordsAudit $audit): RedirectResponse
    {
        $request->validate([
            'mode' => ['required', 'in:full,partial'],
            'amount_minor' => ['required_if:mode,partial', 'nullable', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'in:'.implode(',', array_map(static fn (RefundReason $r): string => $r->value, RefundReason::cases()))],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $mode = $request->string('mode')->toString();
        $netMinor = $mode === 'partial' ? $request->integer('amount_minor') : null;
        $before = ['status' => $invoice->status, 'total_minor' => $invoice->total_minor];

        try {
            $refund = $operations->refund(
                $invoice,
                $netMinor,
                RefundReason::from($request->string('reason')->toString()),
                'op-refund:'.$request->string('idempotency_key')->toString(),
            );
        } catch (InvoiceActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        $audit->record(
            AuditAction::InvoiceRefunded,
            AuditTarget::model($invoice),
            sprintf('Refunded invoice %s as credit note %s (%s).', $invoice->number, $refund->creditNote->number, MoneyFormatter::money($refund->gross)),
            [
                'before' => $before,
                'after' => ['status' => $invoice->fresh()?->status, 'credit_note' => $refund->creditNote->number, 'refund_gross_minor' => $refund->gross->minor()],
                'mode' => $mode,
                'reason' => $request->string('reason')->toString(),
            ],
        );

        return redirect()
            ->route('billing.invoices.show', $invoice->id)
            ->with('status', sprintf(
                'Refund issued as credit note %s (%s).',
                $refund->creditNote->number,
                MoneyFormatter::money($refund->gross),
            ));
    }

    public function markPaid(Request $request, Invoice $invoice, RunsInvoiceOperations $operations): RedirectResponse
    {
        $request->validate(['reference' => ['nullable', 'string', 'max:190']]);

        $operations->markPaid($invoice, $request->filled('reference') ? $request->string('reference')->toString() : null);

        return redirect()
            ->route('billing.invoices.show', $invoice->id)
            ->with('status', sprintf('Invoice %s recorded as paid.', $invoice->number));
    }

    public function resend(Invoice $invoice, RunsInvoiceOperations $operations): RedirectResponse
    {
        $operations->resend($invoice);

        return back()->with('status', sprintf('Invoice %s re-queued to the billing contact.', $invoice->number));
    }

    public function create(Request $request): View
    {
        $selected = $request->query('org');

        return view('billing.invoice-create', [
            'activeArea' => 'invoices',
            'activeNav' => 'all',
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'billing_currency']),
            'selectedOrg' => is_string($selected) ? $selected : null,
        ]);
    }

    public function store(Request $request, RunsInvoiceOperations $operations): RedirectResponse
    {
        $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:190'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.amount_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        $organization = Organization::query()->findOrFail($request->string('organization_id')->toString());

        try {
            $invoice = $operations->createManual($organization, $this->lines($request));
        } catch (InvoiceActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.invoices.show', $invoice->id)
            ->with('status', sprintf('Invoice %s issued.', $invoice->number));
    }

    /**
     * Map the posted line rows into {@see ManualLine} value objects. Amounts are per-unit
     * NET minor units (the app-wide money convention); blank rows fall through and are
     * dropped by the service.
     *
     * @return list<ManualLine>
     */
    private function lines(Request $request): array
    {
        $rows = $request->input('lines');
        $lines = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $description = is_string($row['description'] ?? null) ? $row['description'] : '';
            $quantity = isset($row['quantity']) && is_numeric($row['quantity']) ? (int) $row['quantity'] : 1;
            $amount = isset($row['amount_minor']) && is_numeric($row['amount_minor']) ? (int) $row['amount_minor'] : 0;

            $lines[] = new ManualLine($description, max(1, $quantity), $amount);
        }

        return $lines;
    }
}
