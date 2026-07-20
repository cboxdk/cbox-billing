<?php

declare(strict_types=1);

namespace App\Billing\Experiments\ValueObjects;

use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\PricingTable;

/**
 * The outcome of resolving what a `/pricing/{key}` request should serve, once experiments are
 * taken into account. Three shapes, all carrying the {@see PricingTable} to actually present:
 *
 *  - **plain** — no running/promoted experiment: serve the base table, no attribution/impression.
 *  - **assigned** — a running experiment: serve the assigned variant's table, record an impression
 *    for `(variant, visitor)`, and thread `attribution()` onto the CTA links.
 *  - **promoted** — a concluded experiment with a winner: serve the winner's table, but as the new
 *    canonical page (no assignment, no impression, no attribution — the test is over).
 *
 * The controller reads {@see recordsImpression} to decide whether to log an impression and
 * {@see attribution} to decide whether to thread attribution through the presenter.
 */
readonly class ServedTable
{
    public function __construct(
        public PricingTable $table,
        public ?Experiment $experiment = null,
        public ?ExperimentVariant $variant = null,
        public bool $recordsImpression = false,
    ) {}

    /** The plain outcome: serve the base table unchanged (no experiment intercepts it). */
    public static function plain(PricingTable $table): self
    {
        return new self($table);
    }

    /** A running experiment assigned this variant: serve its table, record an impression. */
    public static function assigned(PricingTable $table, Experiment $experiment, ExperimentVariant $variant): self
    {
        return new self($table, $experiment, $variant, recordsImpression: true);
    }

    /** A concluded experiment's promoted winner: serve its table as the canonical page. */
    public static function promoted(PricingTable $table, Experiment $experiment, ExperimentVariant $variant): self
    {
        return new self($table, $experiment, $variant, recordsImpression: false);
    }

    /**
     * The CTA attribution for the given anonymous visitor, or null when this outcome does not
     * attribute (plain or promoted — only a live assignment threads attribution).
     */
    public function attribution(string $visitorId): ?ExperimentAttribution
    {
        if (! $this->recordsImpression || $this->experiment === null || $this->variant === null) {
            return null;
        }

        return new ExperimentAttribution($this->experiment->key, $this->variant->id, $visitorId);
    }
}
