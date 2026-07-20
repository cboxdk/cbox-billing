<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\FeatureAuthoring;
use App\Billing\Features\Enums\ConfigValueType;
use App\Billing\Features\Enums\FeatureType;
use App\Billing\Reporting\FeatureReport;
use App\Models\Feature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Features console — thin HTTP over {@see FeatureReport} (reads) and {@see FeatureAuthoring}
 * (writes). The boolean/config peer of the Meters console: a feature is a pure on/off capability
 * (boolean) or one that carries a typed value/limit (config). Delete is guarded — a feature a plan
 * grant references is archived, never hard-deleted, so its grants keep resolving.
 */
class FeatureController extends Controller
{
    public function index(Request $request, FeatureReport $report): View
    {
        $search = $this->search($request);

        return view('billing.features', [
            'activeArea' => 'catalog',
            'activeNav' => 'features',
            'search' => $search,
            'features' => $report->paginate($search),
        ]);
    }

    public function show(Feature $feature, FeatureReport $report): View
    {
        return view('billing.feature-detail', [
            'activeArea' => 'catalog',
            'activeNav' => 'features',
            'feature' => $report->find($feature->id),
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(Feature $feature): View
    {
        return $this->form($feature);
    }

    public function store(Request $request, FeatureAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $feature = $authoring->create($data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.features.show', $feature->id)
            ->with('status', sprintf('Feature “%s” created.', $feature->name));
    }

    public function update(Request $request, Feature $feature, FeatureAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->update($feature, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.features.show', $feature->id)
            ->with('status', sprintf('Feature “%s” updated.', $feature->name));
    }

    public function archive(Feature $feature, FeatureAuthoring $authoring): RedirectResponse
    {
        $authoring->archive($feature);

        return back()->with('status', sprintf('Feature “%s” archived.', $feature->name));
    }

    public function unarchive(Feature $feature, FeatureAuthoring $authoring): RedirectResponse
    {
        $authoring->unarchive($feature);

        return back()->with('status', sprintf('Feature “%s” reinstated.', $feature->name));
    }

    public function destroy(Feature $feature, FeatureAuthoring $authoring): RedirectResponse
    {
        $name = $feature->name;

        try {
            $authoring->delete($feature);
        } catch (CatalogActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.features')
            ->with('status', sprintf('Feature “%s” deleted.', $name));
    }

    private function form(?Feature $feature): View
    {
        return view('billing.feature-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'features',
            'feature' => $feature,
            'types' => FeatureType::cases(),
            'valueTypes' => ConfigValueType::cases(),
        ]);
    }

    /**
     * @return array{key: string, name: string, description: ?string, type: FeatureType, value_type: ?ConfigValueType}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(FeatureType::class)],
            'value_type' => ['nullable', Rule::enum(ConfigValueType::class), Rule::requiredIf($request->string('type')->toString() === FeatureType::Config->value)],
        ]);

        $type = FeatureType::from($request->string('type')->toString());

        return [
            'key' => $request->string('key')->toString(),
            'name' => $request->string('name')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
            'type' => $type,
            'value_type' => $type === FeatureType::Config && $request->filled('value_type')
                ? ConfigValueType::from($request->string('value_type')->toString())
                : null,
        ];
    }

    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }
}
