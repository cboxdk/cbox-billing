<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\MeterAuthoring;
use App\Billing\Reporting\MeterReport;
use App\Models\Meter;
use Cbox\Billing\Metering\Enums\Aggregation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Meters console — thin HTTP over {@see MeterReport} (reads) and {@see MeterAuthoring}
 * (writes). A meter's aggregation is the engine {@see Aggregation} its usage is billed
 * with. Delete is guarded: a meter an entitlement or usage references is archived, never
 * hard-deleted, so its historical policy keeps resolving.
 */
class MeterController extends Controller
{
    public function index(Request $request, MeterReport $report): View
    {
        $search = $this->search($request);

        return view('billing.meters', [
            'activeArea' => 'usage',
            'activeNav' => 'meters-manage',
            'search' => $search,
            'meters' => $report->paginate($search),
        ]);
    }

    public function show(Meter $meter, MeterReport $report): View
    {
        return view('billing.meter-detail', [
            'activeArea' => 'usage',
            'activeNav' => 'meters-manage',
            'meter' => $report->find($meter->id),
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(Meter $meter): View
    {
        return $this->form($meter);
    }

    public function store(Request $request, MeterAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $meter = $authoring->create($data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.meters.show', $meter->id)
            ->with('status', sprintf('Meter “%s” created.', $meter->name));
    }

    public function update(Request $request, Meter $meter, MeterAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->update($meter, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.meters.show', $meter->id)
            ->with('status', sprintf('Meter “%s” updated.', $meter->name));
    }

    public function archive(Meter $meter, MeterAuthoring $authoring): RedirectResponse
    {
        $authoring->archive($meter);

        return back()->with('status', sprintf('Meter “%s” archived.', $meter->name));
    }

    public function unarchive(Meter $meter, MeterAuthoring $authoring): RedirectResponse
    {
        $authoring->unarchive($meter);

        return back()->with('status', sprintf('Meter “%s” reinstated.', $meter->name));
    }

    public function destroy(Meter $meter, MeterAuthoring $authoring): RedirectResponse
    {
        $name = $meter->name;

        try {
            $authoring->delete($meter);
        } catch (CatalogActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.meters')
            ->with('status', sprintf('Meter “%s” deleted.', $name));
    }

    private function form(?Meter $meter): View
    {
        return view('billing.meter-form', [
            'activeArea' => 'usage',
            'activeNav' => 'meters-manage',
            'meter' => $meter,
            'aggregations' => Aggregation::cases(),
        ]);
    }

    /**
     * @return array{key: string, name: string, unit: string, aggregation: Aggregation, display: ?string}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'unit' => ['required', 'string', 'max:60'],
            'aggregation' => ['required', Rule::enum(Aggregation::class)],
            'display' => ['nullable', 'string', 'max:160'],
        ]);

        return [
            'key' => $request->string('key')->toString(),
            'name' => $request->string('name')->toString(),
            'unit' => $request->string('unit')->toString(),
            'aggregation' => Aggregation::from($request->string('aggregation')->toString()),
            'display' => $request->filled('display') ? $request->string('display')->toString() : null,
        ];
    }

    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }
}
