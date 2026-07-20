<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Cpq\QuoteApprovalRouter;
use App\Billing\Cpq\QuoteReport;
use App\Models\Quote;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The deal-desk approval queue (CPQ Wave 5). Reads carry `quotes:read`; approve/reject carry
 * `quotes:approve` — a distinct capability from `quotes:manage`, so a rep who authors quotes
 * cannot self-approve their own above-threshold deals. Every decision is audit-logged by the
 * {@see QuoteApprovalRouter}.
 */
class QuoteApprovalController extends Controller
{
    public function index(QuoteReport $report, QuoteApprovalRouter $approvals): View
    {
        return view('billing.quotes.approvals', [
            'activeArea' => 'quotes',
            'activeNav' => 'approvals',
            'quotes' => $report->approvalQueue(),
            'threshold' => $approvals->thresholdSummary(),
        ]);
    }

    public function approve(Quote $quote, QuoteApprovalRouter $approvals): RedirectResponse
    {
        try {
            $approvals->approve($quote);
        } catch (QuoteActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.quotes.show', $quote->id)
            ->with('status', sprintf('Quote %s approved.', $quote->number));
    }

    public function reject(Request $request, Quote $quote, QuoteApprovalRouter $approvals): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $approvals->reject($quote, $request->string('reason')->toString());
        } catch (QuoteActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.quotes.show', $quote->id)
            ->with('status', sprintf('Quote %s rejected and returned to draft.', $quote->number));
    }
}
