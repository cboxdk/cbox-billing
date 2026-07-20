<?php

declare(strict_types=1);

namespace App\Billing\Import;

use App\Billing\Import\Adapters\AdapterRegistry;
use App\Billing\Import\Adapters\SourceExport;
use App\Billing\Import\Enums\ImportSource;
use App\Billing\Import\Normalized\NormalizedDataset;
use App\Billing\Import\ValueObjects\ImportPlan;
use App\Billing\Import\ValueObjects\PlanMapping;
use App\Jobs\ImportCommitJob;
use App\Models\ImportRun;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The glue between a stored {@see ImportRun} and the pipeline: it stages the raw export to disk at
 * dry-run time and re-reads + re-parses exactly those bytes at commit time, so a commit always
 * acts on the same file the operator reviewed. Both the console controller and the queued
 * {@see ImportCommitJob} drive the run through here, so the two paths are identical.
 */
readonly class ImportRunner
{
    public function __construct(
        private AdapterRegistry $adapters,
        private BillingImporter $importer,
        private Filesystem $disk,
    ) {}

    /** Stage the raw export for a run to disk and record its path. */
    public function stage(ImportRun $run, SourceExport $export): void
    {
        $path = 'imports/run_'.$run->id.'.json';
        $this->disk->put($path, (string) json_encode($export->toArray()));
        $run->forceFill(['export_path' => $path])->save();
    }

    /** Re-hydrate a run's staged export. */
    public function loadExport(ImportRun $run): SourceExport
    {
        if ($run->export_path === null || ! $this->disk->exists($run->export_path)) {
            return SourceExport::fromResources([]);
        }

        $decoded = json_decode((string) $this->disk->get($run->export_path), true);
        $files = [];

        if (is_array($decoded)) {
            foreach ($decoded as $resource => $contents) {
                if (is_string($resource) && is_string($contents)) {
                    $files[$resource] = $contents;
                }
            }
        }

        return SourceExport::fromResources($files);
    }

    /** Parse a run's staged export into the normalized model via the run's source adapter. */
    public function parse(ImportRun $run): NormalizedDataset
    {
        $adapter = $this->adapters->get(ImportSource::from($run->source));

        return $adapter->parse($this->loadExport($run));
    }

    /** The dry-run plan for a run against its (possibly operator-adjusted) mapping. */
    public function plan(ImportRun $run): ImportPlan
    {
        return $this->importer->plan(
            ImportSource::from($run->source),
            $this->parse($run),
            $this->mapping($run),
        );
    }

    /** Commit a run for real. */
    public function commit(ImportRun $run): ImportPlan
    {
        return $this->importer->commit($run, $this->parse($run), $this->mapping($run));
    }

    private function mapping(ImportRun $run): PlanMapping
    {
        return PlanMapping::fromArray($run->plan_mapping ?? []);
    }
}
