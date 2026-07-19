<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Audit\Contracts\AssemblesDsarBundle;
use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Contracts\RedactsSubjectData;
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
 * access export) and {@see RedactsSubjectData} (right-to-be-forgotten). Both actions are
 * themselves audit-logged: the export records a `dsar.exported` event; the erasure records
 * `data.erased` (from the service). The page is honest about the redact-vs-retain policy —
 * financial documents are retained (de-identified), never hard-deleted.
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

    /** Erase (pseudonymize) the subject's PII, retaining financial records; records the erasure. */
    public function erase(Organization $organization, RedactsSubjectData $eraser): RedirectResponse
    {
        if ($organization->isErased()) {
            return back()->with('error', sprintf('Organization %s is already erased.', $organization->id));
        }

        $result = $eraser->erase($organization);

        return back()->with('status', sprintf(
            'Erased PII for %s — %d field(s) redacted, %d certificate document(s) deleted; %d invoice(s) and %d credit note(s) retained (de-identified).',
            $organization->id,
            count($result->redactedFields),
            $result->certificateDocumentsDeleted,
            $result->retained['invoices'] ?? 0,
            $result->retained['credit_notes'] ?? 0,
        ));
    }
}
