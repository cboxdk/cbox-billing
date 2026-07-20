<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\PlanFeatureAuthoring;
use App\Billing\Features\FeatureEntitlements;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A plan's boolean/config feature-grant editor — thin HTTP over {@see PlanFeatureAuthoring}.
 * Reachable from the plan detail page (alongside the metered entitlement + credit-grant editors);
 * each grant is one `(plan, feature)` row the {@see FeatureEntitlements}
 * resolver reads. Validated against the features that exist (deny-by-default: an unknown feature is
 * refused).
 */
class PlanFeatureController extends Controller
{
    public function create(Plan $plan): View
    {
        return $this->form($plan, null);
    }

    public function edit(Plan $plan, PlanFeature $feature): View
    {
        return $this->form($plan, $feature);
    }

    public function store(Request $request, Plan $plan, PlanFeatureAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->create($plan, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Feature grant added.');
    }

    public function update(Request $request, Plan $plan, PlanFeature $feature, PlanFeatureAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->update($plan, $feature, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Feature grant updated.');
    }

    public function destroy(Plan $plan, PlanFeature $feature, PlanFeatureAuthoring $authoring): RedirectResponse
    {
        $authoring->delete($feature);

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Feature grant removed — the feature reverts to deny-by-default for this plan.');
    }

    private function form(Plan $plan, ?PlanFeature $grant): View
    {
        // Active features for the picker, plus the grant's own feature when editing (even if
        // archived) so its current selection is never dropped.
        $features = Feature::query()
            ->where(function ($query) use ($grant): void {
                $query->whereNull('archived_at');

                if ($grant?->feature_id !== null) {
                    $query->orWhere('id', $grant->feature_id);
                }
            })
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'type', 'value_type']);

        return view('billing.plan-feature-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'plans',
            'plan' => $plan,
            'grant' => $grant,
            'features' => $features,
        ]);
    }

    /**
     * @return array{feature_id: int, enabled: bool, value: ?string}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'feature_id' => ['required', 'integer', 'exists:features,id'],
            'enabled' => ['nullable', 'boolean'],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'feature_id' => $request->integer('feature_id'),
            'enabled' => $request->boolean('enabled'),
            'value' => $request->filled('value') ? $request->string('value')->toString() : null,
        ];
    }
}
