<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Export\DataExporter;
use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Mode\BillingContext;
use App\Models\WarehouseSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Exports console area (Data → Exports): pick a dataset, a format and an optional date range,
 * and stream a download of the current plane; plus the recent sync-run log. Thin over
 * {@see DataExporter} and {@see DatasetRegistry} — it validates HTTP input, resolves the plane
 * from the ambient {@see BillingContext}, and hands off; the streaming and scoping live in the
 * exporter. Reads carry `analytics:read` (declared on the routes).
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly DatasetRegistry $registry,
        private readonly BillingContext $context,
    ) {}

    public function index(): View
    {
        $datasets = array_map(static fn ($dataset): array => [
            'key' => $dataset->key(),
            'label' => $dataset->label(),
            'description' => $dataset->description(),
            'sync_mode' => $dataset->syncMode()->value,
            'columns' => count($dataset->schema()),
            'date_column' => $dataset->dateColumn(),
        ], $this->registry->all());

        return view('billing.exports.index', [
            'activeArea' => 'data',
            'activeNav' => 'exports',
            'datasets' => $datasets,
            'formats' => array_map(static fn (ExportFormat $f): string => $f->value, ExportFormat::cases()),
            'livemode' => $this->context->livemode(),
            'runs' => WarehouseSyncRun::query()->latest('id')->limit(20)->get(),
        ]);
    }

    /**
     * Stream a dataset download in the requested format, scoped to the current plane and an
     * optional inclusive date range.
     */
    public function download(Request $request, DataExporter $exporter): StreamedResponse
    {
        $dataset = (string) $request->query('dataset');
        $format = ExportFormat::parse(is_string($request->query('format')) ? $request->query('format') : null);

        if (! $this->registry->has($dataset)) {
            throw ValidationException::withMessages(['dataset' => 'Unknown dataset.']);
        }

        $from = $this->date($request->query('from'), startOfDay: true);
        $to = $this->date($request->query('to'), startOfDay: false);

        $query = ExportQuery::window($this->context->livemode(), $from, $to);

        return $exporter->download($dataset, $format, $query);
    }

    /** Parse a `YYYY-MM-DD` query value into a day boundary, or null when absent/invalid. */
    private function date(mixed $value, bool $startOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d', trim($value));

        if (! $parsed instanceof CarbonImmutable) {
            return null;
        }

        return $startOfDay ? $parsed->startOfDay() : $parsed->endOfDay();
    }
}
