<?php

declare(strict_types=1);

namespace App\Billing\Audit\Redaction;

use App\Billing\Audit\Contracts\RecordsAudit;
use App\Billing\Audit\Contracts\RedactsSubjectData;
use App\Billing\Audit\Contracts\ResolvesAuditActor;
use App\Billing\Audit\Enums\AuditAction;
use App\Billing\Audit\ValueObjects\AuditTarget;
use App\Billing\Audit\ValueObjects\ErasureResult;
use App\Models\CreditNote;
use App\Models\GatewayCustomer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\TaxExemptionCertificate;
use App\Models\WalletAdjustment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The right-to-be-forgotten action. It pseudonymizes the subject's PII in place and records the
 * retained-vs-redacted outcome, honouring the tension between GDPR erasure and statutory
 * financial-record retention:
 *
 *  REDACTED (PII → tombstone / deleted):
 *   - organization: `name` → tombstone, `billing_email`, `tax_id`, `billing_subdivision` → null;
 *   - tax exemption certificates: the stored document is DELETED from disk and its descriptors
 *     (path/name/mime/size) + notes are cleared (the certificate row itself is a tax record, so
 *     it is kept — de-identified);
 *   - gateway customer mappings: DETACHED (the local pointer into the gateway's card vault is
 *     removed; the operator still deletes the customer at the gateway out-of-band).
 *
 *  RETAINED (statutory retention — de-identified, never hard-deleted):
 *   - invoices, credit notes, the ledger, wallet adjustments, payments. These reference the org
 *     only by its opaque id (a pseudonymous handle) and carry no name/email of their own, so
 *     redacting the org row removes the PII while the money trail stays intact and auditable.
 *
 * The action records ONE `data.erased` audit event and marks the org `erased`. The audit event
 * stores field NAMES and counts, never the erased values — the trail is PII-minimized by
 * construction, so an immutable event never resurrects what erasure removed.
 */
readonly class SubjectErasureService implements RedactsSubjectData
{
    public function __construct(
        private ConnectionInterface $db,
        private RecordsAudit $audit,
        private ResolvesAuditActor $actors,
        private Filesystem $documents,
    ) {}

    public function erase(Organization $organization): ErasureResult
    {
        $result = $this->db->transaction(function () use ($organization): ErasureResult {
            $certificatesDeleted = $this->redactCertificates($organization);
            $gatewayDetached = $this->detachGateway($organization);
            $redactedFields = $this->redactOrganization($organization);

            return new ErasureResult(
                organizationId: $organization->id,
                redactedFields: $redactedFields,
                certificateDocumentsDeleted: $certificatesDeleted,
                gatewayMappingsDetached: $gatewayDetached,
                retained: $this->retainedCounts($organization->id),
            );
        });

        $this->audit->record(
            AuditAction::DataErased,
            AuditTarget::of('organization', $organization->id, $organization->id),
            sprintf('Erased PII for organization %s — financial records retained (de-identified).', $organization->id),
            [
                'redacted_fields' => $result->redactedFields,
                'certificate_documents_deleted' => $result->certificateDocumentsDeleted,
                'gateway_mappings_detached' => $result->gatewayMappingsDetached,
                'retained' => $result->retained,
            ],
        );

        return $result;
    }

    /**
     * Replace the org's PII with tombstones and stamp the erasure marker.
     *
     * @return list<string> the fields that were redacted
     */
    private function redactOrganization(Organization $organization): array
    {
        $tombstone = sprintf('[erased organization %s]', substr(hash('sha256', $organization->id), 0, 8));

        $organization->forceFill([
            'name' => $tombstone,
            'billing_email' => null,
            'tax_id' => null,
            'tax_id_validated' => false,
            'billing_subdivision' => null,
            'erased_at' => Carbon::now(),
            'erased_by_sub' => $this->actors->resolve()->sub,
        ])->save();

        return ['name', 'billing_email', 'tax_id', 'billing_subdivision'];
    }

    /** Delete each certificate document from disk and clear its descriptors; keep the tax row. */
    private function redactCertificates(Organization $organization): int
    {
        $deleted = 0;

        foreach (TaxExemptionCertificate::query()->where('organization_id', $organization->id)->get() as $certificate) {
            $path = $certificate->document_path;

            if ($path !== null && $path !== '' && $this->documents->exists($path)) {
                $this->documents->delete($path);
                $deleted++;
            }

            $certificate->forceFill([
                'document_path' => null,
                'document_name' => null,
                'document_mime' => null,
                'document_size' => null,
                'notes' => null,
            ])->save();
        }

        return $deleted;
    }

    /** Remove the local gateway-customer mappings (detach the pointer into the card vault). */
    private function detachGateway(Organization $organization): int
    {
        $mappings = GatewayCustomer::query()->where('organization_id', $organization->id)->count();

        GatewayCustomer::query()->where('organization_id', $organization->id)->delete();

        return $mappings;
    }

    /**
     * The retained financial-record counts — what erasure deliberately did NOT remove.
     *
     * @return array<string, int>
     */
    private function retainedCounts(string $organizationId): array
    {
        return [
            'invoices' => Invoice::query()->where('organization_id', $organizationId)->count(),
            'credit_notes' => CreditNote::query()->where('organization_id', $organizationId)->count(),
            'wallet_adjustments' => WalletAdjustment::query()->where('organization_id', $organizationId)->count(),
        ];
    }
}
