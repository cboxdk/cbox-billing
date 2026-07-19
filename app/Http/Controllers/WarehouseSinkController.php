<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Export\DatasetRegistry;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\Enums\Warehouse;
use App\Billing\Export\WarehouseManifestPreview;
use App\Billing\Export\WarehouseSyncService;
use App\Models\WarehouseSink;
use App\Models\WarehouseSyncRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console CRUD + operations for warehouse sinks (Data → Warehouse). Configure a sink (disk,
 * prefix, format, plane, warehouse dialect, datasets, schedule), review its sync-run log, run a
 * sync now, and view the generated per-warehouse load manifest. Reads carry `settings:read`,
 * writes `settings:manage` (declared on the routes). Thin over {@see WarehouseSyncService} and
 * {@see WarehouseManifestPreview}.
 */
class WarehouseSinkController extends Controller
{
    public function __construct(private readonly DatasetRegistry $registry) {}

    public function index(): View
    {
        return view('billing.exports.warehouse', [
            'activeArea' => 'data',
            'activeNav' => 'warehouse',
            'sinks' => WarehouseSink::query()->orderBy('id')->get(),
            'runsBySink' => WarehouseSyncRun::query()->latest('id')->limit(300)->get()->groupBy('sink_id'),
            'datasetOptions' => array_map(static fn ($d): array => ['key' => $d->key(), 'label' => $d->label()], $this->registry->all()),
            'warehouses' => Warehouse::cases(),
            'formats' => ExportFormat::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        WarehouseSink::create($data);

        return redirect()->route('billing.exports.warehouse')->with('status', 'Warehouse sink created.');
    }

    public function update(Request $request, WarehouseSink $warehouseSink): RedirectResponse
    {
        $data = $this->validated($request, $warehouseSink);

        $warehouseSink->update($data);

        return redirect()->route('billing.exports.warehouse')->with('status', 'Warehouse sink updated.');
    }

    public function toggle(WarehouseSink $warehouseSink): RedirectResponse
    {
        $warehouseSink->update(['enabled' => ! $warehouseSink->enabled]);

        return redirect()->route('billing.exports.warehouse')
            ->with('status', $warehouseSink->enabled ? 'Sink enabled.' : 'Sink disabled.');
    }

    public function destroy(WarehouseSink $warehouseSink): RedirectResponse
    {
        $warehouseSink->delete();

        return redirect()->route('billing.exports.warehouse')->with('status', 'Warehouse sink removed.');
    }

    public function run(WarehouseSink $warehouseSink, WarehouseSyncService $service): RedirectResponse
    {
        $runs = $service->syncSink($warehouseSink);
        $rows = array_sum(array_map(static fn ($r): int => (int) $r->rows, $runs));

        return redirect()->route('billing.exports.warehouse')
            ->with('status', sprintf('Synced %d dataset(s), staged %d row(s).', count($runs), $rows));
    }

    public function manifest(WarehouseSink $warehouseSink, string $dataset, WarehouseManifestPreview $preview): View
    {
        abort_unless($this->registry->has($dataset), 404);

        return view('billing.exports.manifest', [
            'activeArea' => 'data',
            'activeNav' => 'warehouse',
            'sink' => $warehouseSink,
            'dataset' => $this->registry->get($dataset),
            'manifest' => $preview->for($warehouseSink, $this->registry->get($dataset)),
        ]);
    }

    /**
     * Validate and normalise sink input into a mass-assignable array.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WarehouseSink $existing = null): array
    {
        $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/', Rule::unique('warehouse_sinks', 'key')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:120'],
            'warehouse' => ['required', Rule::enum(Warehouse::class)],
            'disk' => ['required', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:200'],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'livemode' => ['sometimes', 'boolean'],
            'datasets' => ['required', 'array', 'min:1'],
            'datasets.*' => ['string', Rule::in($this->registry->keys())],
            'schedule' => ['nullable', 'string', 'max:64'],
            'external_base' => ['nullable', 'string', 'max:255'],
            'target_schema' => ['nullable', 'string', 'max:120'],
            'target_stage' => ['nullable', 'string', 'max:120'],
            'credential' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $datasets = $request->input('datasets');

        return [
            'key' => $request->string('key')->toString(),
            'name' => $request->string('name')->toString(),
            'warehouse' => $request->string('warehouse')->toString(),
            'disk' => $request->string('disk')->toString(),
            'prefix' => $request->filled('prefix') ? $request->string('prefix')->toString() : '',
            'format' => $request->string('format')->toString(),
            'livemode' => $request->boolean('livemode'),
            'datasets' => is_array($datasets) ? array_values(array_filter($datasets, 'is_string')) : [],
            'schedule' => $request->filled('schedule') ? $request->string('schedule')->toString() : null,
            'external_base' => $request->filled('external_base') ? $request->string('external_base')->toString() : null,
            'target_schema' => $request->filled('target_schema') ? $request->string('target_schema')->toString() : null,
            'target_stage' => $request->filled('target_stage') ? $request->string('target_stage')->toString() : null,
            'credential' => $request->filled('credential') ? $request->string('credential')->toString() : null,
            'enabled' => $request->boolean('enabled', true),
        ];
    }
}
