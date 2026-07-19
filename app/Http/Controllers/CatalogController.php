<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\Contracts\AuthorsPlanPrices;
use App\Billing\Catalog\Exceptions\CatalogActionDenied;
use App\Billing\Catalog\Exceptions\CatalogAuthoringException;
use App\Billing\Catalog\ValueObjects\PlanPriceDraft;
use App\Models\Plan;
use App\Models\PlanPrice;
use Cbox\Billing\Catalog\Enums\PricingModel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The catalog pricing-authoring console — thin HTTP over the {@see AuthorsPlanPrices}
 * service. It renders the create/edit form and drives the store/update actions; the
 * service validates the tier set against the engine and persists. No logic lives here.
 */
class CatalogController extends Controller
{
    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(PlanPrice $price): View
    {
        return $this->form($price->load(['plan.product', 'tiers']));
    }

    public function store(Request $request, AuthorsPlanPrices $authoring): RedirectResponse
    {
        return $this->persist($request, $authoring, null);
    }

    public function update(Request $request, PlanPrice $price, AuthorsPlanPrices $authoring): RedirectResponse
    {
        return $this->persist($request, $authoring, $price);
    }

    /**
     * Remove a price version and its tier set. Guarded by the currency-lock invariant: the
     * effective price a serving subscriber grandfathers on cannot be pulled out from under
     * them (the service refuses it), so the guard is enforced server-side — never on the
     * confirm dialog alone.
     */
    public function destroyPrice(PlanPrice $price, AuthorsPlanPrices $authoring): RedirectResponse
    {
        $planModel = $price->plan;
        $plan = $planModel instanceof Plan ? $planModel->name : 'plan';
        $currency = $price->currency;

        try {
            $authoring->delete($price);
        } catch (CatalogActionDenied $e) {
            return back()->with('catalog_error', $e->getMessage());
        }

        return redirect()
            ->route('billing.catalog')
            ->with('catalog_notice', sprintf('Removed the %s %s price.', $plan, $currency));
    }

    /**
     * Mark a plan retiring (ADR-0016): set its sunset cutoff and, optionally, the default
     * successor its subscribers fall to at renewal if they make no choice. A successor must
     * be a different, active plan; without one, an unresolved subscriber is flagged rather
     * than migrated.
     */
    public function retire(Request $request, Plan $plan): RedirectResponse
    {
        $request->validate([
            'retires_at' => ['required', 'date'],
            'default_successor_plan_id' => ['nullable', 'integer', Rule::notIn([$plan->id]), Rule::exists('plans', 'id')->where('active', true)],
        ]);

        $plan->forceFill([
            'retires_at' => $request->date('retires_at'),
            'default_successor_plan_id' => $request->filled('default_successor_plan_id') ? $request->integer('default_successor_plan_id') : null,
        ])->save();

        return redirect()
            ->route('billing.catalog')
            ->with('catalog_notice', sprintf('%s is retiring on %s.', $plan->name, $plan->retires_at?->format('j M Y')));
    }

    /** Un-retire a plan: clear its sunset cutoff and default successor. */
    public function unretire(Plan $plan): RedirectResponse
    {
        $plan->forceFill(['retires_at' => null, 'default_successor_plan_id' => null])->save();

        return redirect()
            ->route('billing.catalog')
            ->with('catalog_notice', sprintf('%s is no longer retiring.', $plan->name));
    }

    /** Render the price form, pre-filled from an existing price when editing. */
    private function form(?PlanPrice $price): View
    {
        return view('billing.plan-price-form', [
            'activeArea' => 'catalog',
            'activeNav' => $price === null ? 'price-new' : 'products',
            'price' => $price,
            'plans' => Plan::query()->with('product')->orderBy('name')->get(),
            'models' => PricingModel::cases(),
        ]);
    }

    /** Validate input, delegate to the service, and flash the outcome back to the catalog. */
    private function persist(Request $request, AuthorsPlanPrices $authoring, ?PlanPrice $price): RedirectResponse
    {
        $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'pricing_model' => ['required', Rule::enum(PricingModel::class)],
            'price_minor' => ['required', 'integer', 'min:0'],
            'package_size' => ['nullable', 'integer', 'min:1'],
            'tiers' => ['array'],
            'tiers.*.up_to' => ['nullable', 'integer', 'min:1'],
            'tiers.*.unit_minor' => ['nullable', 'integer', 'min:0'],
            'tiers.*.flat_minor' => ['nullable', 'integer', 'min:0'],
        ]);

        // On edit the plan + currency are fixed by the price being edited; on create they
        // come from the form. Read through the typed request accessors (never raw casts).
        $draft = new PlanPriceDraft(
            planId: $price !== null ? $price->plan_id : $request->integer('plan_id'),
            currency: $price !== null ? $price->currency : strtoupper($request->string('currency')->toString()),
            model: PricingModel::from($request->string('pricing_model')->toString()),
            priceMinor: $request->integer('price_minor'),
            packageSize: $request->filled('package_size') ? $request->integer('package_size') : null,
            tiers: $this->tiers($request->array('tiers')),
        );

        try {
            $saved = $authoring->save($draft);
        } catch (CatalogAuthoringException $e) {
            return back()->withInput()->with('catalog_error', $e->getMessage());
        }

        $planName = Plan::query()->whereKey($saved->plan_id)->value('name');

        return redirect()
            ->route('billing.catalog')
            ->with('catalog_notice', sprintf(
                'Saved %s %s price (%s).',
                is_string($planName) ? $planName : 'plan',
                $saved->currency,
                $saved->model()->value,
            ));
    }

    /**
     * Normalize the submitted tier rows to the draft shape: an empty "up to" is the
     * unbounded final tier, amounts default to zero/none.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<array{up_to: int|null, unit_minor: int, flat_minor: int|null}>
     */
    private function tiers(array $rows): array
    {
        $tiers = [];

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : [];
            $upTo = $row['up_to'] ?? null;
            $unit = $row['unit_minor'] ?? null;
            $flat = $row['flat_minor'] ?? null;

            $tiers[] = [
                'up_to' => is_numeric($upTo) ? (int) $upTo : null,
                'unit_minor' => is_numeric($unit) ? (int) $unit : 0,
                'flat_minor' => is_numeric($flat) ? (int) $flat : null,
            ];
        }

        return $tiers;
    }
}
