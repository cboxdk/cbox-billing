<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The scoping applied to one export: the billing ENVIRONMENT (the named plane the rows are read
 * from — two sandboxes are never mixed in a single export), an optional inclusive business date
 * range on the dataset's date column, and an optional incremental watermark (`afterCursor`) an
 * incremental sync passes so only rows strictly past the last delivery are streamed.
 *
 * `environment` is what the datasets FILTER on (so a DSAR/export in one named sandbox never
 * includes another's rows); `livemode` is retained as the byte-stable true/false mirror the
 * exported `livemode` column and the warehouse output partition are phrased against (the external
 * data-contract). The two agree for the canonical planes (production ↔ true, sandbox ↔ false).
 *
 * It starts fully-open on a plane: no range and no watermark selects that plane's whole dataset.
 * An optional `organizationId` narrows the export to a single subject's rows — the seam the
 * DSAR (data-subject access) bundle reuses to assemble one organization's records.
 */
readonly class ExportQuery
{
    public function __construct(
        public string $environment,
        public bool $livemode,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $afterCursor = null,
        public ?string $organizationId = null,
    ) {}

    /** The whole of a plane's dataset (no range, no watermark). */
    public static function plane(string $environment, bool $livemode): self
    {
        return new self($environment, $livemode);
    }

    /** A plane scoped to an inclusive business-date window. */
    public static function window(string $environment, bool $livemode, ?CarbonImmutable $from, ?CarbonImmutable $to): self
    {
        return new self($environment, $livemode, $from, $to);
    }

    /** A plane narrowed to a single organization's rows (the DSAR access-export scope). */
    public static function forOrganization(string $environment, bool $livemode, string $organizationId): self
    {
        return new self($environment, $livemode, null, null, null, $organizationId);
    }

    /** The same query advanced past a stored watermark (incremental sync). */
    public function after(?string $cursor): self
    {
        return new self($this->environment, $this->livemode, $this->from, $this->to, $cursor, $this->organizationId);
    }

    public function hasRange(): bool
    {
        return $this->from !== null || $this->to !== null;
    }
}
