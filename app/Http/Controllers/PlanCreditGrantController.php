<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Catalog\PlanCreditGrantAuthoring;
use App\Billing\Wallet\Enums\AmountMode;
use App\Models\Plan;
use App\Models\PlanCreditGrant;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use Cbox\Billing\Wallet\Enums\GrantKind;
use Cbox\Billing\Wallet\Support\Pools;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A plan's credit-grant editor — thin HTTP over {@see PlanCreditGrantAuthoring}. Reachable
 * from the plan detail page; authors the `(pool, kind, cadence, amount, amount_mode)` tuple
 * the engine's wallet vests each cadence boundary. Editing or removing a grant only changes
 * what vests from the next boundary on — already-vested credits stand.
 */
class PlanCreditGrantController extends Controller
{
    public function create(Plan $plan): View
    {
        return $this->form($plan, null);
    }

    public function edit(Plan $plan, PlanCreditGrant $creditGrant): View
    {
        return $this->form($plan, $creditGrant);
    }

    public function store(Request $request, Plan $plan, PlanCreditGrantAuthoring $authoring): RedirectResponse
    {
        $authoring->create($plan, $this->validated($request));

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Credit grant added.');
    }

    public function update(Request $request, Plan $plan, PlanCreditGrant $creditGrant, PlanCreditGrantAuthoring $authoring): RedirectResponse
    {
        $authoring->update($creditGrant, $this->validated($request));

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Credit grant updated.');
    }

    public function destroy(Plan $plan, PlanCreditGrant $creditGrant, PlanCreditGrantAuthoring $authoring): RedirectResponse
    {
        $authoring->delete($creditGrant);

        return redirect()
            ->route('billing.plans.show', $plan->id)
            ->with('status', 'Credit grant removed — nothing further vests from it.');
    }

    private function form(Plan $plan, ?PlanCreditGrant $creditGrant): View
    {
        return view('billing.plan-credit-grant-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'plans',
            'plan' => $plan,
            'grant' => $creditGrant,
            'pools' => [Pools::INCLUDED, Pools::PROMOTIONAL, Pools::PURCHASED, Pools::REGULATED],
            'kinds' => GrantKind::cases(),
            'cadences' => GrantCadence::cases(),
            'amountModes' => AmountMode::cases(),
        ]);
    }

    /**
     * @return array{pool: string, kind: GrantKind, cadence: GrantCadence, amount: int, amount_mode: AmountMode, rollover_seconds: ?int, denomination: string}
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'pool' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::enum(GrantKind::class)],
            'cadence' => ['required', Rule::enum(GrantCadence::class)],
            'amount' => ['required', 'integer', 'min:0'],
            'amount_mode' => ['required', Rule::enum(AmountMode::class)],
            'rollover_seconds' => ['nullable', 'integer', 'min:0'],
            'denomination' => ['required', 'string', 'max:60'],
        ]);

        return [
            'pool' => $request->string('pool')->toString(),
            'kind' => GrantKind::from($request->string('kind')->toString()),
            'cadence' => GrantCadence::from($request->string('cadence')->toString()),
            'amount' => $request->integer('amount'),
            'amount_mode' => AmountMode::from($request->string('amount_mode')->toString()),
            'rollover_seconds' => $request->filled('rollover_seconds') ? $request->integer('rollover_seconds') : null,
            'denomination' => $request->string('denomination')->toString(),
        ];
    }
}
