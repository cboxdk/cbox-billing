<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Import\Adapters\AdapterRegistry;
use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\BillingImporter;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\Normalized\NormalizedDataset;
use App\Billing\Import\ValueObjects\ImportPlan;
use App\Billing\Import\ValueObjects\PlanMapping;
use App\Models\ImportRun;

/**
 * Shared helpers for the import tests: load a provider fixture export, parse it through the real
 * adapter, and dry-run / commit it through the real pipeline (over the real domain services).
 */
trait ImportsFixtures
{
    protected function importExport(ImportSource $source): SourceExport
    {
        $path = base_path('tests/Fixtures/imports/'.$source->value.'.json');

        return SourceExport::fromCombinedJson((string) file_get_contents($path));
    }

    protected function importDataset(ImportSource $source, ?SourceExport $export = null): NormalizedDataset
    {
        $export ??= $this->importExport($source);

        return app(AdapterRegistry::class)->get($source)->parse($export);
    }

    protected function newImportRun(ImportSource $source): ImportRun
    {
        return ImportRun::query()->create([
            'source' => $source->value,
            'status' => 'planned',
            'dry_run' => true,
        ]);
    }

    /**
     * @return array{0: ImportRun, 1: ImportPlan}
     */
    protected function commitImport(ImportSource $source, ?PlanMapping $mapping = null, ?NormalizedDataset $data = null): array
    {
        $run = $this->newImportRun($source);
        $data ??= $this->importDataset($source);
        $plan = app(BillingImporter::class)->commit($run, $data, $mapping ?? new PlanMapping);

        return [$run->refresh(), $plan];
    }

    protected function planImport(ImportSource $source, ?PlanMapping $mapping = null, ?NormalizedDataset $data = null): ImportPlan
    {
        $data ??= $this->importDataset($source);

        return app(BillingImporter::class)->plan($source, $data, $mapping ?? new PlanMapping);
    }
}
