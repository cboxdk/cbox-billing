<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Coupons\Exceptions\CouponRedemptionDenied;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\ValueObjects\AddOnRequest;
use App\Billing\Support\MoneyFormatter;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The subscription operator-lifecycle console (Wave 3) — thin HTTP over the engine-backed
 * {@see SubscribesOrganizations} (subscribe, change plan) and {@see ManagesSubscriptionDepth}
 * (quantity, add-ons, scheduled changes). Every money-moving action goes preview → confirm:
 * the preview step renders the engine quote (prorated due-now, new recurring, effective
 * date), and the confirm step commits through the SAME engine call, so what the operator
 * confirms is exactly what is charged (preview == charge).
 */
class SubscriptionOpsController extends Controller
{
    public function create(Request $request): View
    {
        $selected = $request->query('org');

        return view('billing.subscription-create', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'all',
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'billing_currency']),
            'plans' => Plan::query()->with('prices')->where('active', true)->orderBy('name')->get(),
            'selectedOrg' => is_string($selected) ? $selected : null,
        ]);
    }

    public function store(Request $request, SubscribesOrganizations $subscriptions, CouponRedeemer $coupons): RedirectResponse
    {
        $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'plan' => ['required', 'string', 'exists:plans,key'],
            'currency' => ['required', 'string', 'size:3'],
            'seats' => ['required', 'integer', 'min:1'],
            'trial' => ['sometimes', 'boolean'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'coupon' => ['nullable', 'string', 'max:60'],
        ]);

        $organization = Organization::query()->findOrFail($request->string('organization_id')->toString());
        $plan = Plan::query()->with('prices')->where('key', $request->string('plan')->toString())->firstOrFail();
        $currency = strtoupper($request->string('currency')->toString());

        if (! $plan->prices->contains('currency', $currency)) {
            return back()->withInput()->with('error', sprintf('%s is not priced in %s.', $plan->name, $currency));
        }

        // Validate the promo code before subscribing (deny-by-default), so a bad code flashes
        // back rather than leaving a coupon-less subscription.
        $couponCode = $request->filled('coupon') ? $request->string('coupon')->toString() : null;
        $coupon = null;

        if ($couponCode !== null) {
            try {
                $coupon = $coupons->validate($couponCode, $plan, $currency, $organization->id);
            } catch (CouponRedemptionDenied $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        }

        $seats = $request->integer('seats');
        $wantsTrial = $request->boolean('trial');

        $subscription = $wantsTrial
            ? $subscriptions->subscribeWithTrial($organization, $plan, $request->filled('trial_days') ? $request->integer('trial_days') : null, $seats, $currency)
            : $subscriptions->subscribe($organization, $plan, $seats, $currency);

        $note = '';

        if ($coupon instanceof Coupon) {
            $coupons->redeem($coupon, $subscription);
            $note = sprintf(' Coupon %s applied.', $coupon->code);
        }

        return redirect()
            ->route('billing.subscriptions.show', $subscription->id)
            ->with('status', sprintf('Subscribed %s to %s.%s', $organization->name, $plan->name, $note));
    }

    public function planChangePreview(Request $request, Subscription $subscription, SubscribesOrganizations $subscriptions): View
    {
        $request->validate(['plan' => ['required', 'string'], 'when' => ['required', 'in:now,period_end']]);

        $subscription->loadMissing(['plan.product', 'organization']);
        $newPlan = $this->planByKey($request->string('plan')->toString());

        $preview = $subscriptions->previewChange($subscription, $newPlan);
        $when = $request->string('when')->toString();

        return $this->review($subscription, [
            'title' => sprintf('Change to %s', $newPlan->name),
            'description' => $when === 'period_end'
                ? 'Scheduled for the current period end — nothing is charged now.'
                : 'Applied immediately, prorated over the days still to run.',
            'confirm' => route('billing.subscriptions.plan-change', $subscription),
            'confirmLabel' => $when === 'period_end' ? 'Schedule change' : 'Confirm change',
            'hidden' => ['plan' => $newPlan->key, 'when' => $when],
            'stats' => $this->planChangeStats($preview, $when),
        ]);
    }

    public function planChange(Request $request, Subscription $subscription, SubscribesOrganizations $subscriptions, ManagesSubscriptionDepth $depth): RedirectResponse
    {
        $request->validate(['plan' => ['required', 'string'], 'when' => ['required', 'in:now,period_end']]);

        $subscription->loadMissing(['plan.product', 'organization']);
        $newPlan = $this->planByKey($request->string('plan')->toString());

        if ($request->string('when')->toString() === 'period_end') {
            $depth->scheduleChange($subscription, $newPlan);

            return redirect()->route('billing.subscriptions.show', $subscription->id)
                ->with('status', sprintf('Scheduled change to %s at period end.', $newPlan->name));
        }

        $subscriptions->changePlan($subscription, $newPlan);

        return redirect()->route('billing.subscriptions.show', $subscription->id)
            ->with('status', sprintf('Changed to %s.', $newPlan->name));
    }

    public function quantityPreview(Request $request, Subscription $subscription, ManagesSubscriptionDepth $depth): View
    {
        $request->validate(['seats' => ['required', 'integer', 'min:1']]);

        $subscription->loadMissing(['plan.prices.tiers', 'organization']);
        $seats = $request->integer('seats');
        $preview = $depth->previewQuantity($subscription, $seats);
        $charge = $preview->charge;

        return $this->review($subscription, [
            'title' => sprintf('Change quantity to %d', $seats),
            'description' => 'Prorated over the days still to run — a reduction nets a credit.',
            'confirm' => route('billing.subscriptions.quantity', $subscription),
            'confirmLabel' => 'Confirm quantity',
            'hidden' => ['seats' => (string) $seats],
            'stats' => [
                ['label' => 'From', 'value' => (string) $preview->fromSeats],
                ['label' => 'To', 'value' => (string) $preview->toSeats],
                ['label' => $preview->isCredit() ? 'Credit' : 'Due now', 'value' => MoneyFormatter::money($preview->isCredit() ? $charge->negated() : $charge)],
            ],
        ]);
    }

    public function quantity(Request $request, Subscription $subscription, ManagesSubscriptionDepth $depth): RedirectResponse
    {
        $request->validate(['seats' => ['required', 'integer', 'min:1']]);

        $subscription->loadMissing(['plan.prices.tiers', 'organization']);
        $depth->changeQuantity($subscription, $request->integer('seats'));

        return redirect()->route('billing.subscriptions.show', $subscription->id)
            ->with('status', sprintf('Quantity changed to %d.', $request->integer('seats')));
    }

    public function addOnPreview(Request $request, Subscription $subscription, ManagesSubscriptionDepth $depth): View
    {
        $data = $this->validatedAddOn($request);
        $subscription->loadMissing(['plan', 'organization']);
        $preview = $depth->previewAddOn($subscription, $this->addOnRequest($data));

        return $this->review($subscription, [
            'title' => sprintf('Add add-on “%s”', $data['key']),
            'description' => 'Prorated over the days still to run in the add-on\'s billing period.',
            'confirm' => route('billing.subscriptions.addons.add', $subscription),
            'confirmLabel' => 'Confirm add-on',
            'hidden' => [
                'key' => $data['key'], 'price_minor' => (string) $data['price_minor'], 'currency' => $data['currency'],
                'alignment' => $data['alignment'], 'credit_allotment' => (string) $data['credit_allotment'],
                'interval' => $data['interval'] ?? '',
            ],
            'stats' => [
                ['label' => 'Charge now', 'value' => MoneyFormatter::minor($preview['charge_minor'], $preview['currency'])],
                ['label' => 'Credit allotment', 'value' => (string) $preview['allotment']],
                ['label' => 'Alignment', 'value' => $preview['alignment']],
            ],
        ]);
    }

    public function addAddOn(Request $request, Subscription $subscription, ManagesSubscriptionDepth $depth): RedirectResponse
    {
        $data = $this->validatedAddOn($request);
        $subscription->loadMissing(['plan', 'organization']);
        $depth->addAddOn($subscription, $this->addOnRequest($data));

        return redirect()->route('billing.subscriptions.show', $subscription->id)
            ->with('status', sprintf('Add-on “%s” attached.', $data['key']));
    }

    public function removeAddOn(Request $request, Subscription $subscription, ManagesSubscriptionDepth $depth): RedirectResponse
    {
        $request->validate(['key' => ['required', 'string']]);
        $key = $request->string('key')->toString();

        $removed = $depth->removeAddOn($subscription, $key);

        return redirect()->route('billing.subscriptions.show', $subscription->id)
            ->with($removed ? 'status' : 'error', $removed ? sprintf('Add-on “%s” removed.', $key) : 'No such add-on.');
    }

    public function cancelScheduledChange(Subscription $subscription): RedirectResponse
    {
        if (! $subscription->hasPendingChange()) {
            return back()->with('error', 'There is no scheduled change to cancel.');
        }

        $subscription->forceFill(['pending_plan_id' => null, 'pending_effective_at' => null])->save();

        return redirect()->route('billing.subscriptions.show', $subscription->id)
            ->with('status', 'Scheduled change canceled.');
    }

    /**
     * Render the generic preview→confirm review page.
     *
     * @param  array{title: string, description: string, confirm: string, confirmLabel: string, hidden: array<string, string>, stats: list<array{label: string, value: string}>}  $data
     */
    private function review(Subscription $subscription, array $data): View
    {
        return view('billing.subscription-action-review', [
            'activeArea' => 'subscriptions',
            'activeNav' => 'all',
            'subscription' => $subscription,
        ] + $data);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function planChangeStats(PlanChangePreview $preview, string $when): array
    {
        $dueNow = $preview->dueNowQuote?->totals->gross;

        return [
            ['label' => 'Due now', 'value' => $when === 'period_end' || $dueNow === null ? '—' : MoneyFormatter::money($dueNow)],
            ['label' => 'New recurring', 'value' => MoneyFormatter::money($preview->newRecurring)],
            ['label' => 'Effective', 'value' => $preview->effectiveAt->format('Y-m-d')],
            ['label' => 'Credits forfeited', 'value' => (string) $preview->creditDelta->forfeited],
            ['label' => 'Credits granted', 'value' => (string) $preview->creditDelta->granted],
        ];
    }

    /**
     * @return array{key: string, price_minor: int, currency: string, alignment: string, credit_allotment: int, interval: string|null}
     */
    private function validatedAddOn(Request $request): array
    {
        $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'alignment' => ['required', 'in:aligned,independent'],
            'credit_allotment' => ['nullable', 'integer', 'min:0'],
            'interval' => ['nullable', 'in:monthly,yearly'],
        ]);

        return [
            'key' => $request->string('key')->toString(),
            'price_minor' => $request->integer('price_minor'),
            'currency' => strtoupper($request->string('currency')->toString()),
            'alignment' => $request->string('alignment')->toString(),
            'credit_allotment' => $request->integer('credit_allotment', 0),
            'interval' => $request->filled('interval') ? $request->string('interval')->toString() : null,
        ];
    }

    /**
     * @param  array{key: string, price_minor: int, currency: string, alignment: string, credit_allotment: int, interval: string|null}  $data
     */
    private function addOnRequest(array $data): AddOnRequest
    {
        return new AddOnRequest(
            key: $data['key'],
            priceMinor: $data['price_minor'],
            currency: $data['currency'],
            alignment: AddOnAlignment::from($data['alignment']),
            creditAllotment: $data['credit_allotment'],
            interval: $data['interval'] !== null ? BillingInterval::from($data['interval']) : null,
        );
    }

    private function planByKey(string $key): Plan
    {
        return Plan::query()->with(['prices', 'product'])->where('key', $key)->firstOrFail();
    }
}
