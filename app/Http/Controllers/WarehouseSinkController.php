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
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;

/**
 * Console CRUD + operations for warehouse sinks (Data → Warehouse). Configure a sink (disk,
 * prefix, format, plane, warehouse dialect, datasets, schedule), review its sync-run log, run a
 * sync now, and view the generated per-warehouse load manifest. Reads carry `settings:read`,
 * writes `settings:manage` (declared on the routes). Thin over {@see WarehouseSyncService} and
 * {@see WarehouseManifestPreview}.
 *
 * The sink config is operator input that becomes a `Storage::disk()` target and load-manifest
 * SQL, so it is hardened deny-by-default: `disk` is allow-listed (config); `external_base` must
 * use an approved warehouse URI scheme; and `target_schema`/`target_stage` are constrained to a
 * safe SQL-identifier charset so they can never inject into a generated COPY/DDL statement.
 */
class WarehouseSinkController extends Controller
{
    /** A safe, dotted SQL identifier (schema / schema.table) — letters, digits, underscore only. */
    private const SQL_IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** A safe stage reference — a SQL identifier optionally prefixed with `@` (Snowflake stage). */
    private const STAGE_IDENTIFIER = '/^@?[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** A safe credential reference — an ARN / integration name (no whitespace or SQL metacharacters). */
    private const CREDENTIAL_REF = '/^[A-Za-z0-9_:\/.\-]+$/';

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
            'disk' => ['required', 'string', 'max:64', Rule::in($this->allowedDisks())],
            'prefix' => ['nullable', 'string', 'max:200'],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'livemode' => ['sometimes', 'boolean'],
            'datasets' => ['required', 'array', 'min:1'],
            'datasets.*' => ['string', Rule::in($this->registry->keys())],
            'schedule' => ['nullable', 'string', 'max:64'],
            'external_base' => ['nullable', 'string', 'max:255', $this->externalBaseRule()],
            'target_schema' => ['nullable', 'string', 'max:120', 'regex:'.self::SQL_IDENTIFIER],
            'target_stage' => ['nullable', 'string', 'max:120', 'regex:'.self::STAGE_IDENTIFIER],
            'credential' => ['nullable', 'string', 'max:255', 'regex:'.self::CREDENTIAL_REF],
            'enabled' => ['sometimes', 'boolean'],
        ], [
            'disk.in' => 'That export disk is not on the allow-list.',
            'target_schema.regex' => 'The target schema must be a plain SQL identifier.',
            'target_stage.regex' => 'The target stage must be a plain SQL identifier.',
            'credential.regex' => 'The credential reference contains unsupported characters.',
        ]);

        $datasets = $request->input('datasets');

        return $this->normalized($request, $datasets);
    }

    /**
     * The filesystem disks a sink may stage to (config allow-list), always including the export
     * default so a fresh install works out of the box.
     *
     * @return list<string>
     */
    private function allowedDisks(): array
    {
        $configured = Config::get('billing.export.allowed_disks');
        $disks = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];

        $default = Config::get('billing.export.default_disk');
        if (is_string($default) && $default !== '') {
            $disks[] = $default;
        }

        return array_values(array_unique($disks));
    }

    /**
     * The `external_base` scheme guard: an absent value passes; a present one must be an absolute
     * URI whose scheme is on the approved warehouse-scheme allow-list (deny-by-default).
     */
    private function externalBaseRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $scheme = parse_url($value, PHP_URL_SCHEME);
            $allowed = Config::get('billing.export.warehouse_uri_schemes');
            $allowed = is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : [];

            if (! is_string($scheme) || ! in_array(strtolower($scheme), array_map('strtolower', $allowed), true)) {
                $fail('The external base must use an approved warehouse URI scheme.');
            }
        };
    }

    /**
     * Normalise validated sink input into a mass-assignable array.
     *
     * @return array<string, mixed>
     */
    private function normalized(Request $request, mixed $datasets): array
    {
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
