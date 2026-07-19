<?php

declare(strict_types=1);

namespace App\Billing\Export;

use App\Billing\Export\Contracts\ExportDataset;
use InvalidArgumentException;

/**
 * The registry of every export dataset, keyed by its stable slug. Bound as a singleton in the
 * service provider with the full set; a plugin or a host override can supply its own set through
 * the container. Deny-by-default: an unknown key is a hard error, never a silent empty export.
 */
class DatasetRegistry
{
    /** @var array<string, ExportDataset> */
    private array $datasets = [];

    /**
     * @param  iterable<ExportDataset>  $datasets
     */
    public function __construct(iterable $datasets = [])
    {
        foreach ($datasets as $dataset) {
            $this->register($dataset);
        }
    }

    public function register(ExportDataset $dataset): void
    {
        $this->datasets[$dataset->key()] = $dataset;
    }

    public function has(string $key): bool
    {
        return isset($this->datasets[$key]);
    }

    public function get(string $key): ExportDataset
    {
        return $this->datasets[$key]
            ?? throw new InvalidArgumentException("Unknown export dataset [{$key}].");
    }

    /**
     * All datasets, in registration order.
     *
     * @return list<ExportDataset>
     */
    public function all(): array
    {
        return array_values($this->datasets);
    }

    /**
     * The registered dataset keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->datasets);
    }
}
