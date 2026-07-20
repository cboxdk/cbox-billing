<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Billing\Import\ImportRunner;
use App\Billing\Mode\BillingContext;
use App\Billing\Mode\BillingMode;
use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Commit a large import off the request cycle. A queued commit runs the SAME
 * {@see ImportRunner::commit()} the inline path does; because the importer is idempotent and
 * re-runnable, a retried/failed job never duplicates. The run's plane is re-asserted on the
 * ambient {@see BillingContext} inside the worker so writes land in the plane the operator
 * planned in (a worker starts in live by default).
 */
class ImportCommitJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $runId) {}

    public function handle(ImportRunner $runner, BillingContext $context): void
    {
        $run = ImportRun::withoutGlobalScopes()->find($this->runId);

        if (! $run instanceof ImportRun) {
            return;
        }

        $context->setMode(BillingMode::fromLivemode((bool) $run->livemode));

        try {
            $runner->commit($run);
        } catch (Throwable $e) {
            $run->forceFill(['status' => 'failed', 'notes' => $e->getMessage()])->save();

            throw $e;
        }
    }
}
