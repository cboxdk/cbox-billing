<?php

declare(strict_types=1);

namespace App\Models;

use App\Billing\Tax\Exemptions\Enums\ExemptionStatus;
use App\Billing\Tax\Exemptions\Enums\ExemptionType;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tax exemption certificate an organization holds for a jurisdiction. Its verified,
 * non-expired existence is the ONLY thing that zero-rates tax for the matching jurisdiction —
 * everything else is still taxed (deny-by-default).
 *
 * `jurisdiction` is either an ISO 3166-2 subdivision (`US-CA`) or a country code (`US`
 * federal, `DK`). {@see coversPlace()} is the single match rule the tax seam trusts: a
 * subdivision-scoped certificate exempts only that subdivision; a country-scoped one exempts
 * the whole country (including its subdivisions).
 *
 * The uploaded document lives on the PRIVATE disk at `document_path`; it is never web-served
 * without an authz check (see the console download action).
 *
 * @property int $id
 * @property string $organization_id
 * @property string $jurisdiction
 * @property ExemptionType $exemption_type
 * @property string $certificate_number
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property ExemptionStatus $status
 * @property string|null $document_path
 * @property string|null $document_name
 * @property string|null $document_mime
 * @property int|null $document_size
 * @property string|null $verified_by_sub
 * @property Carbon|null $verified_at
 * @property string|null $notes
 */
class TaxExemptionCertificate extends Model
{
    protected $fillable = [
        'organization_id', 'jurisdiction', 'exemption_type', 'certificate_number',
        'issued_at', 'expires_at', 'status', 'document_path', 'document_name',
        'document_mime', 'document_size', 'verified_by_sub', 'verified_at', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exemption_type' => ExemptionType::class,
            'status' => ExemptionStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'document_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether this certificate actually exempts right now: verified AND not past its expiry.
     * A null expiry never expires. This is the belt to the scheduled command's braces — a
     * verified-but-past-expiry certificate does not exempt even before the sweep flips it.
     */
    public function isActiveNow(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return $this->status === ExemptionStatus::Verified
            && ($this->expires_at === null || $this->expires_at->greaterThan($at));
    }

    public function isExpired(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at);
    }

    /**
     * Whether this certificate covers a transaction's resolved place of supply. A
     * subdivision-scoped certificate (`US-CA`) matches only that exact subdivision; a
     * country/federal-scoped one (`US`, `DK`) matches the whole country. This is the ONLY
     * jurisdiction-match rule — a CA certificate never exempts NY.
     */
    public function coversPlace(Jurisdiction $place): bool
    {
        $scope = strtoupper(trim($this->jurisdiction));

        if (str_contains($scope, '-')) {
            return $place->subdivision !== null && $place->subdivision->value === $scope;
        }

        return $place->country->value === $scope;
    }

    /** The audit reason recorded on an invoice this certificate exempted. */
    public function exemptionReason(): string
    {
        return sprintf(
            'Tax exempt — %s certificate %s (%s).',
            $this->exemption_type->label(),
            $this->certificate_number,
            $this->jurisdiction,
        );
    }

    /**
     * Certificates that currently exempt: verified and not past their expiry.
     *
     * @param  Builder<TaxExemptionCertificate>  $query
     */
    public function scopeActive(Builder $query, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();

        $query->where('status', ExemptionStatus::Verified->value)
            ->where(static function ($q) use ($at): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $at);
            });
    }
}
