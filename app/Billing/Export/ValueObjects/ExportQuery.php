<?php

declare(strict_types=1);

namespace App\Billing\Export\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The scoping applied to one export: the billing PLANE (`livemode` true = live, false = test —
 * the two are never mixed in a single export), an optional inclusive business date range on the
 * dataset's date column, and an optional incremental watermark (`afterCursor`) an incremental
 * sync passes so only rows strictly past the last delivery are streamed.
 *
 * It starts fully-open on a plane: no range and no watermark selects that plane's whole dataset.
 */
readonly class ExportQuery
{
    public function __construct(
        public bool $livemode,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $afterCursor = null,
    ) {}

    /** The whole of a plane's dataset (no range, no watermark). */
    public static function plane(bool $livemode): self
    {
        return new self($livemode);
    }

    /** A plane scoped to an inclusive business-date window. */
    public static function window(bool $livemode, ?CarbonImmutable $from, ?CarbonImmutable $to): self
    {
        return new self($livemode, $from, $to);
    }

    /** The same query advanced past a stored watermark (incremental sync). */
    public function after(?string $cursor): self
    {
        return new self($this->livemode, $this->from, $this->to, $cursor);
    }

    public function hasRange(): bool
    {
        return $this->from !== null || $this->to !== null;
    }
}
