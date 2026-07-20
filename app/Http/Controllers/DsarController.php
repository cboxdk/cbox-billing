<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Approvals\ApprovableActionRegistry;
use App\Billing\Approvals\ApprovalGate;
use App\Billing\Approvals\Enums\ApprovalActionType;
use App\Billing\Audit\Contracts\AssemblesDsarBundle;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Mode\BillingContext;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The GDPR / DSAR tooling console — thin HTTP over {@see AssemblesDsarBundle} (data-subject
 * access export) and the {@see ApprovalGate} for the right-to-be-forgotten erasure. Both actions
 * are audit-logged: the export records a `dsar.exported` event; the erasure records `data.erased`
 * (from the service). The page is honest about the redact-vs-retain policy — financial documents
 * are retained (de-identified), never hard-deleted.
 *
 * The erasure hard-deletes the subject's certificate documents (irreversible), so it is routed
 * through the maker-checker {@see ApprovalGate} — by default it needs a SECOND operator to approve
 * before anything is destroyed, consistent with refunds/suspensions.
 */
class DsarController extends Controller
{
    public function index(Request $request): View
    {
        $search = is_string($request->query('q')) ? trim($request->query('q')) : '';

        $organizations = Organization::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where('id', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('billing.audit.gdpr', [
            'activeArea' => 'audit',
            'activeNav' => 'gdpr',
            'organizations' => $organizations,
            'search' => $search,
        ]);
    }

    /** Build and download the subject's DSAR bundle for the current plane; records the export. */
    public function export(Organization $organization, AssemblesDsarBundle $assembler, RecordsAudit $audit, BillingContext $context): BinaryFileResponse
    {
        $bundle = $assembler->build($organization, $context->livemode());

        $audit->record(
            AuditAction::DsarExported,
            AuditTarget::of('organization', $organization->id, $organization->id),
            sprintf('DSAR access bundle exported for organization %s (%d rows across %d datasets).', $organization->id, $bundle->totalRows(), count($bundle->datasets())),
            [
                'plane' => $bundle->livemode ? 'live' : 'test',
                'datasets' => $bundle->datasetCounts,
                'total_rows' => $bundle->totalRows(),
            ],
        );

        return response()->download($bundle->path, $bundle->filename, [
            'Content-Type' => 'application/gzip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Erase (pseudonymize) the subject's PII, retaining financial records. Routed through the
     * maker-checker gate: when approval is required (the default) the erasure is HELD for a second
     * operator and nothing is destroyed until they approve; when disabled it runs immediately,
     * byte-for-byte the pre-gate behaviour. Records the erasure (from the held action).
     */
    public function erase(Organization $organization, ApprovableActionRegistry $registry, ApprovalGate $gate): RedirectResponse
    {
        if ($organization->isErased()) {
            return back()->with('error', sprintf('Organization %s is already erased.', $organization->id));
        }

        $action = $registry->build(ApprovalActionType::DataErase, ['organization_id' => $organization->id]);
        $result = $gate->run($action, 'Operator-initiated DSAR erasure');

        if ($result->wasHeld()) {
            return back()->with('status', sprintf(
                'Erasure of %s submitted for approval (request #%d) — no data is deleted until a second operator approves.',
                $organization->id,
                $result->request?->id,
            ));
        }

        return back()->with('status', $result->outcome !== null
            ? $result->outcome->summary
            : sprintf('Erased PII for %s.', $organization->id));
    }
}
