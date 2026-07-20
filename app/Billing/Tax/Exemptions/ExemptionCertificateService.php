<?php

declare(strict_types=1);

namespace App\Billing\Tax\Exemptions;

use App\Billing\Tax\Exemptions\Enums\ExemptionStatus;
use App\Models\Organization;
use App\Models\TaxExemptionCertificate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The write model for exemption certificates: capture (with the document stored on the
 * PRIVATE disk), and the operator verify/reject lifecycle. Both the console and the portal
 * go through here, so the deny-by-default rule (an upload always lands `pending`) and the
 * secure-storage rule (never the public disk) live in exactly one place.
 */
class ExemptionCertificateService
{
    /** The private disk exemption documents are stored on — never web-served without authz. */
    public const DISK = 'local';

    private const DIRECTORY = 'tax-exemptions';

    /**
     * Capture a new certificate for an organization, storing its document on the private
     * disk and landing it `pending` for operator review. `$attributes` carries the already
     * validated jurisdiction / type / number / dates / notes.
     *
     * @param  array{jurisdiction: string, exemption_type: string, certificate_number: string, issued_at?: ?string, expires_at?: ?string, notes?: ?string}  $attributes
     */
    public function capture(Organization $organization, array $attributes, UploadedFile $document): TaxExemptionCertificate
    {
        $path = $document->storeAs(
            sprintf('%s/%s', self::DIRECTORY, $organization->id),
            sprintf('%s.%s', Str::uuid()->toString(), $document->getClientOriginalExtension() ?: 'bin'),
            ['disk' => self::DISK],
        );

        return TaxExemptionCertificate::query()->create([
            'organization_id' => $organization->id,
            'jurisdiction' => strtoupper(trim($attributes['jurisdiction'])),
            'exemption_type' => $attributes['exemption_type'],
            'certificate_number' => trim($attributes['certificate_number']),
            'issued_at' => $this->date($attributes['issued_at'] ?? null),
            'expires_at' => $this->date($attributes['expires_at'] ?? null),
            'status' => ExemptionStatus::Pending,
            'document_path' => $path,
            'document_name' => $document->getClientOriginalName(),
            'document_mime' => $document->getClientMimeType(),
            'document_size' => $document->getSize(),
            'notes' => isset($attributes['notes']) && $attributes['notes'] !== '' ? $attributes['notes'] : null,
        ]);
    }

    /** Operator verify — the certificate now exempts; records who verified it and when. */
    public function verify(TaxExemptionCertificate $certificate, ?string $operatorSub): void
    {
        $certificate->forceFill([
            'status' => ExemptionStatus::Verified,
            'verified_by_sub' => $operatorSub,
            'verified_at' => Carbon::now(),
        ])->save();
    }

    /** Operator reject — records who rejected it, when, and an optional reason. Never exempts. */
    public function reject(TaxExemptionCertificate $certificate, ?string $operatorSub, ?string $notes = null): void
    {
        $certificate->forceFill([
            'status' => ExemptionStatus::Rejected,
            'verified_by_sub' => $operatorSub,
            'verified_at' => Carbon::now(),
            'notes' => $notes !== null && $notes !== '' ? $notes : $certificate->notes,
        ])->save();
    }

    /** The private disk the documents live on. */
    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    private function date(?string $value): ?Carbon
    {
        return $value !== null && $value !== '' ? Carbon::parse($value) : null;
    }
}
