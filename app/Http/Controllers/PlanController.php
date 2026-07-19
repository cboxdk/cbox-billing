<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\PlanAuthoring;
use App\Billing\Reporting\PlanReport;
use App\Models\Plan;
use App\Models\Product;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Plans console — thin HTTP over {@see PlanReport} (the per-plan detail hub the catalog
 * editors hang off) and {@see PlanAuthoring} (metadata writes). Plan edits never touch a
 * plan's money — that is the versioned per-currency price authoring, which grandfathers
 * subscribers — so editing here cannot reprice anyone. Delete is archive-when-subscribed.
 */
class PlanController extends Controller
{
    /**
     * The intervals a plan can bill on. Restricted to the two the billing engine can
     * actually represent and renew — {@see BillingInterval}
     * carries only Monthly and Yearly. Authoring `week`/`quarter` was silently billed on a
     * monthly cadence (a quarter over-charged 3×, a week under-charged), so they are refused
     * here rather than mis-billed; a proper sub-monthly/quarterly cadence needs an engine
     * feature, not an app workaround.
     */
    private const INTERVALS = ['month', 'year'];

    public function show(Plan $plan, PlanReport $report): View
    {
        return view('billing.plan-detail', [
            'activeArea' => 'catalog',
            'activeNav' => 'plans',
            'plan' => $report->find($plan->id),
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(Plan $plan): View
    {
        return $this->form($plan);
    }

    public function store(Request $request, PlanAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $plan = $authoring->create($data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', sprintf('Plan “%s” created. Add its per-currency prices next.', $plan->name));
    }

    public function update(Request $request, Plan $plan, PlanAuthoring $authoring): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $authoring->update($plan, $data);
        } catch (CatalogActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', sprintf('Plan “%s” updated.', $plan->name));
    }

    public function archive(Plan $plan, PlanAuthoring $authoring): RedirectResponse
    {
        $authoring->archive($plan);

        return back()->with('status', sprintf('Plan “%s” archived — it is now legacy and closed to new signups.', $plan->name));
    }

    public function unarchive(Plan $plan, PlanAuthoring $authoring): RedirectResponse
    {
        $authoring->unarchive($plan);

        return back()->with('status', sprintf('Plan “%s” re-offered to new signups.', $plan->name));
    }

    public function destroy(Plan $plan, PlanAuthoring $authoring): RedirectResponse
    {
        $name = $plan->name;

        try {
            $authoring->delete($plan);
        } catch (CatalogActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.catalog')
            ->with('catalog_notice', sprintf('Plan “%s” deleted.', $name));
    }

    private function form(?Plan $plan): View
    {
        // Active products for the picker, plus the plan's own product when editing (even if
        // it has since been archived) so the current selection is never dropped.
        $products = Product::query()
            ->where(function ($query) use ($plan): void {
                $query->whereNull('archived_at');

                if ($plan?->product_id !== null) {
                    $query->orWhere('id', $plan->product_id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'key']);

        return view('billing.plan-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'plans',
            'plan' => $plan,
            'products' => $products,
            'intervals' => self::INTERVALS,
        ]);
    }

    /**
     * @return array{product_id: int, key: string, name: string, interval: string, active: bool}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'interval' => ['required', 'string', 'in:'.implode(',', self::INTERVALS)],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            'product_id' => $request->integer('product_id'),
            'key' => $request->string('key')->toString(),
            'name' => $request->string('name')->toString(),
            'interval' => $request->string('interval')->toString(),
            'active' => $request->boolean('active'),
        ];
    }
}
