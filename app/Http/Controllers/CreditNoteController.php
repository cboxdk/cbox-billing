<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Invoicing\CreditNotePdfRenderer;
use App\Billing\Reporting\CreditNoteReport;
use App\Models\CreditNote;
use Cbox\Billing\Events\CreditNoteIssued;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Credit notes console (Wave 3) — read-only list + detail over {@see CreditNoteReport}.
 * Credit notes are the legal record of refunds/adjustments, created only by the engine's
 * {@see CreditNoteIssued} event, so there is no authoring here — the
 * record surface is cross-linked from the invoice and the customer.
 */
class CreditNoteController extends Controller
{
    public function index(Request $request, CreditNoteReport $report): View
    {
        $search = $this->search($request);

        return view('billing.credit-notes', [
            'activeArea' => 'invoices',
            'activeNav' => 'credit-notes',
            'search' => $search,
            'creditNotes' => $report->paginate($search),
        ]);
    }

    public function show(CreditNote $creditNote): View
    {
        return view('billing.credit-note-detail', [
            'activeArea' => 'invoices',
            'activeNav' => 'credit-notes',
            'note' => $creditNote->load(['organization', 'lines', 'invoice']),
        ]);
    }

    /** `GET` — download the credit note as a legal PDF (gated `invoices:read` at the route). */
    public function pdf(CreditNote $creditNote, CreditNotePdfRenderer $renderer): Response
    {
        return new Response($renderer->render($creditNote), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($creditNote).'"',
        ]);
    }

    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }
}
