<?php

declare(strict_types=1);

namespace App\Billing\Audit\ValueObjects;

/**
 * A built DSAR archive: the on-disk path of the (tar.gz) bundle, the download filename to serve
 * it under, and the per-dataset row counts that went into it (for the audit event and the UI).
 */
readonly class DsarBundle
{
    /**
     * @param  array<string, int>  $datasetCounts  dataset key → row count included in the bundle
     */
    public function __construct(
        public string $path,
        public string $filename,
        public string $organizationId,
        public bool $livemode,
        public array $datasetCounts,
    ) {}

    /** Total rows across every dataset in the bundle. */
    public function totalRows(): int
    {
        return array_sum($this->datasetCounts);
    }

    /**
     * The dataset keys that contributed at least one row.
     *
     * @return list<string>
     */
    public function datasets(): array
    {
        return array_keys($this->datasetCounts);
    }
}
