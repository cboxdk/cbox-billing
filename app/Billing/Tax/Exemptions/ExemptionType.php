<?php

declare(strict_types=1);

namespace App\Billing\Tax\Exemptions;

/**
 * The kind of tax exemption a certificate evidences. US B2B sales tax recognises a handful
 * of distinct exemption grounds (resale, nonprofit, government), each with its own paperwork
 * and certificate-number shape; `other` is the generic catch-all for jurisdictions and
 * grounds outside that set.
 */
enum ExemptionType: string
{
    case Resale = 'resale';
    case Nonprofit = 'nonprofit';
    case Government = 'government';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resale => 'resale',
            self::Nonprofit => 'nonprofit',
            self::Government => 'government',
            self::Other => 'exemption',
        };
    }

    /**
     * A per-type sanity pattern for the certificate number. These are deliberately permissive
     * — the goal is to reject obvious garbage (empty, too short, illegal characters), not to
     * validate a certificate against an authority (that is the operator's verify step).
     */
    public function certificateNumberPattern(): string
    {
        return match ($this) {
            // Resale/seller's-permit numbers: alphanumeric with dashes, e.g. a CDTFA permit.
            self::Resale => '/^[A-Za-z0-9][A-Za-z0-9\-]{2,39}$/',
            // Nonprofit: an EIN-shaped or state exemption number.
            self::Nonprofit => '/^[A-Za-z0-9][A-Za-z0-9\-]{2,39}$/',
            // Government purchase orders / exemption ids may carry slashes.
            self::Government => '/^[A-Za-z0-9][A-Za-z0-9\-\/]{1,39}$/',
            // Generic: allow spaces for free-form references.
            self::Other => '/^[A-Za-z0-9][A-Za-z0-9\-\/ ]{1,59}$/',
        };
    }

    public function acceptsCertificateNumber(string $number): bool
    {
        return preg_match($this->certificateNumberPattern(), trim($number)) === 1;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
