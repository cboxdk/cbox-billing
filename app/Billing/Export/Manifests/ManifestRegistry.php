<?php

declare(strict_types=1);

namespace App\Billing\Export\Manifests;

use App\Billing\Export\Contracts\LoadManifestGenerator;
use App\Billing\Export\Enums\Warehouse;

/**
 * Resolves the {@see LoadManifestGenerator} for a warehouse dialect. {@see Warehouse::None} has
 * no generator (a staged-files-only sink), which is returned as null — the sink then simply
 * omits a load manifest, honestly.
 */
class ManifestRegistry
{
    /** @var array<string, LoadManifestGenerator> */
    private array $generators = [];

    /**
     * @param  iterable<LoadManifestGenerator>  $generators
     */
    public function __construct(iterable $generators = [])
    {
        foreach ($generators as $generator) {
            $this->generators[$generator->warehouse()->value] = $generator;
        }
    }

    public function for(Warehouse $warehouse): ?LoadManifestGenerator
    {
        return $this->generators[$warehouse->value] ?? null;
    }
}
