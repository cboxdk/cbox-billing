<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\PlanEntitlementAuthoring;
use App\Models\Meter;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A plan's metered entitlement editor — thin HTTP over {@see PlanEntitlementAuthoring}.
 * Reachable from the plan detail page; each entitlement is one `(plan, meter)` allowance
 * bucket the engine's metering policy resolves from. Validated against the meters that
 * exist (deny-by-default: an unknown meter is refused).
 */
class PlanEntitlementController extends Controller
{
    public function create(Plan $plan): View
    {
        return $this->form($plan, null);
    }

    public function edit(Plan $plan, PlanEntitlement $entitlement): View
    {
        return $this->form($plan, $entitlement);
    }

    public function store(Request $request, Plan $plan, PlanEntitlementAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->create($plan, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Entitlement added.');
    }

    public function update(Request $request, Plan $plan, PlanEntitlement $entitlement, PlanEntitlementAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->update($plan, $entitlement, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Entitlement updated.');
    }

    public function destroy(Plan $plan, PlanEntitlement $entitlement, PlanEntitlementAuthoring $authoring): RedirectResponse
    {
        $authoring->delete($entitlement);

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Entitlement removed — the meter reverts to deny-by-default for this plan.');
    }

    private function form(Plan $plan, ?PlanEntitlement $entitlement): View
    {
        // Active meters for the picker, plus the entitlement's own meter when editing (even
        // if archived) so its current selection is never dropped.
        $meters = Meter::query()
            ->where(function ($query) use ($entitlement): void {
                $query->whereNull('archived_at');

                if ($entitlement?->meter_id !== null) {
                    $query->orWhere('id', $entitlement->meter_id);
                }
            })
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'unit']);

        return view('billing.plan-entitlement-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'plans',
            'plan' => $plan,
            'entitlement' => $entitlement,
            'meters' => $meters,
            'overages' => OverageBehaviour::cases(),
        ]);
    }

    /**
     * @return array{meter_id: int, enabled: bool, unlimited: bool, allowance: int, multiplier: ?float, overage: OverageBehaviour}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'meter_id' => ['required', 'integer', 'exists:meters,id'],
            'enabled' => ['nullable', 'boolean'],
            'unlimited' => ['nullable', 'boolean'],
            'allowance' => ['nullable', 'integer', 'min:0'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'overage' => ['required', Rule::enum(OverageBehaviour::class)],
        ]);

        return [
            'meter_id' => $request->integer('meter_id'),
            'enabled' => $request->boolean('enabled'),
            'unlimited' => $request->boolean('unlimited'),
            'allowance' => $request->integer('allowance'),
            'multiplier' => $request->filled('multiplier') ? (float) $request->float('multiplier') : null,
            'overage' => OverageBehaviour::from($request->string('overage')->toString()),
        ];
    }
}
