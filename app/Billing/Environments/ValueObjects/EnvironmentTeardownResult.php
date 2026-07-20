<?php

declare(strict_types=1);

namespace App\Billing\Environments\ValueObjects;

/**
 * The outcome of a reset or destroy: the plane key, whether the environment row itself was removed
 * (destroy) or kept (reset), and the per-table deleted-row counts — so the caller can report and
 * audit exactly what was torn down.
 */
readonly class EnvironmentTeardownResult
{
    /**
     * @param  array<string, int>  $deletedByTable  table → number of rows deleted
     */
    public function __construct(
        public string $environmentKey,
        public bool $environmentRemoved,
        public array $deletedByTable,
    ) {}

    /** Total rows deleted across every table. */
    public function totalDeleted(): int
    {
        return array_sum($this->deletedByTable);
    }
}
