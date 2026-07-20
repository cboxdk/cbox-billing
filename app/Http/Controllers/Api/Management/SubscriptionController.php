<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Coupons\Contracts\RedeemsCoupons;
use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Coupons\Exceptions\CouponRedemptionDenied;
use App\Billing\Retention\Contracts\ManagesRetention;
use App\Billing\Retention\Enums\CancellationMode;
use App\Billing\Retention\Exceptions\RetentionException;
use App\Billing\Retention\ValueObjects\CancellationRequest;
use App\Billing\Subscriptions\Contracts\ManagesSubscriptionDepth;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\Exceptions\StaleAddOnPreview;
use App\Billing\Subscriptions\ValueObjects\AddOnRequest;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Http\Controllers\Api\ApiController;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use Cbox\Billing\Subscription\Enums\AddOnAlignment;
use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Cbox\Billing\Subscription\Proration\ProrationLine;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The subscription lifecycle + management-depth surface of the management API — thin HTTP
 * over {@see SubscribesOrganizations} and {@see ManagesSubscriptionDepth}: read/subscribe,
 * preview + apply (immediate or deferred) a plan change, cancel, pause/resume, change seat
 * quantity, and attach/detach add-ons. Every write is per-org scoped (a token for org A
 * cannot touch org B → 403) and delegates the engine work; the controller only validates,
 * authorizes, and maps.
 */
class SubscriptionController extends ApiController
{
    /** `GET /api/v1/subscriptions/{org}` — the org's current subscription, or 404. */
    public function show(Request $request, string $org): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        return new JsonResponse($this->present($subscription));
    }

    /**
     * `POST /api/v1/subscriptions` {org, plan, trial?, trial_days?} — subscribe the org to a
     * plan. With `trial: true` (or a positive `trial_days`) the subscription opens in a free
     * trial (`Trialing`, charging nothing) that converts on the scheduled trial pass.
     */
    public function store(Request $request, SubscribesOrganizations $subscriptions, RedeemsCoupons $coupons, ResolvesAccountCurrency $currencies): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'plan' => ['required', 'string'],
            'seats' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'trial' => ['sometimes', 'boolean'],
            'trial_days' => ['sometimes', 'integer', 'min:1'],
            'coupon' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return $this->notFound('Unknown organization.');
        }

        $plan = $this->planByKey($request, $request->string('plan')->toString());

        if (! $plan instanceof Plan) {
            return $this->notFound('Unknown plan.');
        }

        $currency = $request->has('currency') ? strtoupper($request->string('currency')->toString()) : null;
        $seats = $request->integer('seats', 1);
        $effectiveCurrency = $currency ?? $currencies->for($organization);

        // Validate the promo code BEFORE opening the subscription (deny-by-default → 422), so
        // an invalid code never leaves a half-applied subscribe behind. The atomic redeem +
        // bind runs after the subscription exists.
        $couponCode = $request->filled('coupon') ? $request->string('coupon')->toString() : null;
        $coupon = null;

        if ($couponCode !== null) {
            try {
                $coupon = $coupons->validate($couponCode, $plan, $effectiveCurrency, $org);
            } catch (CouponRedemptionDenied $e) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $wantsTrial = $request->boolean('trial') || $request->has('trial_days');

        $subscription = $wantsTrial
            ? $subscriptions->subscribeWithTrial(
                $organization,
                $plan,
                $request->has('trial_days') ? $request->integer('trial_days') : null,
                $seats,
                $currency,
            )
            : $subscriptions->subscribe($organization, $plan, $seats, $currency);

        $couponInfo = null;

        if ($coupon instanceof Coupon) {
            $coupons->redeem($coupon, $subscription);
            $couponInfo = $this->presentCoupon($coupon, $plan, $effectiveCurrency, $seats, $coupons);
        }

        return new JsonResponse([
            'subscription' => $this->present($subscription),
            'coupon' => $couponInfo,
            // The manual gateway settles out of band, so there is no client-confirmable
            // intent yet; the field is reserved for the payment-intents task.
            'payment_intent' => null,
        ], Response::HTTP_CREATED);
    }

    /** `POST /api/v1/subscriptions/{org}/preview` {plan} — the consequence of a change, uncommitted. */
    public function preview(Request $request, string $org, SubscribesOrganizations $subscriptions): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);
        $newPlan = $this->requestedPlan($request);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        if (! $newPlan instanceof Plan) {
            return $this->notFound('Unknown plan.');
        }

        return new JsonResponse($this->presentPreview($subscriptions->previewChange($subscription, $newPlan)));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/change` {plan, when?} — apply a plan change now
     * (`when: now`, the default) or schedule it for the current period end
     * (`when: period_end`). The `scheduled` flag surfaces a deferred change distinctly.
     */
    public function change(Request $request, string $org, SubscribesOrganizations $subscriptions, ManagesSubscriptionDepth $depth): JsonResponse
    {
        $request->validate(['when' => ['sometimes', 'in:now,period_end']]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);
        $newPlan = $this->requestedPlan($request);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        if (! $newPlan instanceof Plan) {
            return $this->notFound('Unknown plan.');
        }

        if ($request->string('when')->toString() === 'period_end') {
            $preview = $depth->scheduleChange($subscription, $newPlan);

            // The scheduled date wins the union over the preview's immediate effective_at.
            return new JsonResponse([
                'scheduled' => true,
                'effective_at' => $subscription->refresh()->pending_effective_at?->toIso8601String(),
            ] + $this->presentPreview($preview));
        }

        return new JsonResponse(['scheduled' => false] + $this->presentPreview($subscriptions->changePlan($subscription, $newPlan)));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/cancel` {mode?, at_period_end?, reason?, feedback?} —
     * the retention fork. `mode` is `immediate`, `period_end`, or `pause` (a
     * pause-instead-of-cancel save); when absent it derives from the legacy `at_period_end`
     * flag (default true → period-end). The `reason`/`feedback` are captured for churn
     * analytics regardless of mode.
     */
    public function cancel(Request $request, string $org, ManagesRetention $retention): JsonResponse
    {
        $request->validate([
            'mode' => ['sometimes', 'in:immediate,period_end,pause'],
            'at_period_end' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'feedback' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        $mode = $request->has('mode')
            ? CancellationMode::from($request->string('mode')->toString())
            : ($request->boolean('at_period_end', true) ? CancellationMode::PeriodEnd : CancellationMode::Immediate);

        $subscription = $retention->cancel($subscription, new CancellationRequest(
            mode: $mode,
            reason: $this->nullableString($request, 'reason'),
            feedback: $this->nullableString($request, 'feedback'),
        ));

        return new JsonResponse($this->present($subscription->refresh()));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/reactivate` — win-back: resume a paused subscription,
     * undo a scheduled period-end cancel, or re-activate one canceled within the win-back
     * window. 409 when the subscription is in none of those reactivatable states.
     */
    public function reactivate(Request $request, string $org, ManagesRetention $retention): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->latestSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no subscription.');
        }

        try {
            $subscription = $retention->reactivate($subscription);
        } catch (RetentionException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($this->present($subscription->refresh()));
    }

    /** `POST /api/v1/subscriptions/{org}/pause` — suspend access + metering until resumed. */
    public function pause(Request $request, string $org, ManagesSubscriptionDepth $depth): JsonResponse
    {
        return $this->onActive($request, $org, static fn (Subscription $s): Subscription => $depth->pause($s));
    }

    /** `POST /api/v1/subscriptions/{org}/resume` — lift a pause. */
    public function resume(Request $request, string $org, ManagesSubscriptionDepth $depth): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        // Resume acts on a PAUSED subscription, which the serving() seam excludes by
        // design — resolve the paused row directly rather than the serving lookup.
        $subscription = $this->pausedSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no paused subscription.');
        }

        return new JsonResponse($this->present($depth->resume($subscription)->refresh()));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/quantity` {seats, preview?} — change the seat
     * quantity with preview-equals-charge proration; `preview: true` computes without
     * applying.
     */
    public function quantity(Request $request, string $org, ManagesSubscriptionDepth $depth): JsonResponse
    {
        $request->validate([
            'seats' => ['required', 'integer', 'min:1'],
            'preview' => ['sometimes', 'boolean'],
        ]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        $seats = $request->integer('seats');
        $isPreview = $request->boolean('preview');

        $result = $isPreview
            ? $depth->previewQuantity($subscription, $seats)
            : $depth->changeQuantity($subscription, $seats);

        return new JsonResponse($this->presentQuantity($result, $isPreview));
    }

    /**
     * `POST /api/v1/subscriptions/{org}/addons` — attach an add-on (aligned or independent),
     * or compute the prorated consequence with `preview: true`.
     */
    public function addAddOn(Request $request, string $org, ManagesSubscriptionDepth $depth): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'alignment' => ['sometimes', 'in:aligned,independent'],
            'credit_allotment' => ['sometimes', 'integer', 'min:0'],
            'anchor_day' => ['sometimes', 'integer', 'min:1', 'max:31'],
            'anchor_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'interval' => ['sometimes', 'in:monthly,yearly'],
            'preview' => ['sometimes', 'boolean'],
            // The "due now" gross a prior preview showed; when present on an apply, the service
            // rejects (409) if the fresh proration has drifted across a period boundary.
            'expected_due_minor' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        $addOnRequest = new AddOnRequest(
            key: $request->string('key')->toString(),
            priceMinor: $request->integer('price_minor'),
            currency: strtoupper($request->string('currency')->toString()),
            alignment: AddOnAlignment::from($request->string('alignment', 'aligned')->toString()),
            creditAllotment: $request->integer('credit_allotment', 0),
            anchorDay: $request->has('anchor_day') ? $request->integer('anchor_day') : null,
            anchorMonth: $request->has('anchor_month') ? $request->integer('anchor_month') : null,
            interval: $request->has('interval') ? BillingInterval::from($request->string('interval')->toString()) : null,
            expectedGrossDueMinor: $request->has('expected_due_minor') ? $request->integer('expected_due_minor') : null,
        );

        if ($request->boolean('preview')) {
            return new JsonResponse($depth->previewAddOn($subscription, $addOnRequest)->toArray());
        }

        $preview = $depth->previewAddOn($subscription, $addOnRequest);

        try {
            $addOn = $depth->addAddOn($subscription, $addOnRequest);
        } catch (StaleAddOnPreview $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'preview' => $preview->toArray(),
            'add_on' => $this->presentAddOn($addOn),
        ], Response::HTTP_CREATED);
    }

    /** `DELETE /api/v1/subscriptions/{org}/addons/{key}` — detach an add-on. */
    public function removeAddOn(Request $request, string $org, string $key, ManagesSubscriptionDepth $depth): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        if (! $depth->removeAddOn($subscription, $key)) {
            return $this->notFound('This organization has no such add-on.');
        }

        return new JsonResponse($this->present($subscription->refresh()));
    }

    /**
     * Resolve the org's active subscription, run `$mutate` on it, and present the result —
     * the shared shape of the pause/resume endpoints.
     *
     * @param  callable(Subscription): Subscription  $mutate
     */
    private function onActive(Request $request, string $org, callable $mutate): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        if ($denied = $this->denyUnlessMayUseOrgProduct($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        return new JsonResponse($this->present($mutate($subscription)->refresh()));
    }

    private function requestedPlan(Request $request): ?Plan
    {
        $request->validate(['plan' => ['required', 'string']]);

        return $this->planByKey($request, $request->string('plan')->toString());
    }

    private function activeSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with(['plan', 'pendingPlan', 'addOns'])
            ->where('organization_id', $org)
            ->serving()
            ->latest('current_period_start')
            ->first();
    }

    /**
     * The org's paused subscription, if any — the lookup {@see resume()} uses, since a
     * paused row is by definition not serving and so is invisible to {@see activeSubscription()}.
     */
    private function pausedSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with(['plan', 'pendingPlan', 'addOns'])
            ->where('organization_id', $org)
            ->whereNotNull('paused_at')
            ->latest('current_period_start')
            ->first();
    }

    /**
     * The org's most recent subscription regardless of status — the lookup reactivation
     * uses, since a paused or canceled subscription is exactly what win-back acts on.
     */
    private function latestSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with(['plan', 'organization', 'pendingPlan', 'addOns'])
            ->where('organization_id', $org)
            ->latest('id')
            ->first();
    }

    /** A trimmed request string, or null when absent/blank — for the optional reason fields. */
    private function nullableString(Request $request, string $key): ?string
    {
        if (! $request->filled($key)) {
            return null;
        }

        $value = trim($request->string($key)->toString());

        return $value === '' ? null : $value;
    }

    /** Resolve a plan key, refusing another product's plan for a product-bound token. */
    private function planByKey(Request $request, string $key): ?Plan
    {
        $plan = Plan::query()->with(['prices', 'product'])->where('key', $key)->first();

        if ($plan !== null && ! $this->identity($request)->mayUseProduct((int) $plan->product_id)) {
            return null;
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    private function present(Subscription $subscription): array
    {
        return [
            'plan' => $subscription->plan?->key,
            'status' => $subscription->standing(),
            'paused' => $subscription->isPaused(),
            'seats' => $subscription->seats,
            'period_start' => $subscription->current_period_start?->toIso8601String(),
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'renews_at' => $subscription->cancel_at_period_end || $subscription->isPaused()
                ? null
                : $subscription->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'canceled_at' => $subscription->canceled_at?->toIso8601String(),
            'pending_change' => $subscription->hasPendingChange()
                ? [
                    'plan' => $subscription->pendingPlan?->key,
                    'effective_at' => $subscription->pending_effective_at?->toIso8601String(),
                ]
                : null,
            'add_ons' => $subscription->addOns->map(fn (SubscriptionAddOn $addOn): array => $this->presentAddOn($addOn))->all(),
        ];
    }

    /**
     * The applied-coupon block: the code, its duration, and the discount it applies to the
     * recurring net — computed through the engine applier ({@see CouponRedeemer::discountFor()}),
     * so the previewed discount is exactly what the invoice collects.
     *
     * @return array<string, mixed>
     */
    private function presentCoupon(Coupon $coupon, Plan $plan, string $currency, int $seats, RedeemsCoupons $coupons): array
    {
        $net = $plan->amountFor($currency, $seats);
        $discount = $coupons->discountFor($coupon, $net);

        return [
            'code' => $coupon->code,
            'duration' => $coupon->duration,
            'currency' => $currency,
            'recurring_minor' => $net->minor(),
            'discount_minor' => $discount?->amount->minor() ?? 0,
            'discounted_minor' => $discount?->discounted->minor() ?? $net->minor(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentAddOn(SubscriptionAddOn $addOn): array
    {
        return [
            'key' => $addOn->key,
            'price_minor' => $addOn->price_minor,
            'currency' => $addOn->currency,
            'alignment' => $addOn->alignment->value,
            'credit_allotment' => $addOn->credit_allotment,
        ];
    }

    /** @return array<string, mixed> */
    private function presentQuantity(QuantityPreview $preview, bool $isPreview): array
    {
        $charge = $preview->charge;

        return [
            'applied' => ! $isPreview,
            'from_seats' => $preview->fromSeats,
            'to_seats' => $preview->toSeats,
            // The tax-aware GROSS actually collected (preview == charge), not bare net.
            'due_now_minor' => $preview->grossDueNow->minor(),
            'credit_minor' => $preview->isCredit() ? $charge->negated()->minor() : 0,
            'currency' => $charge->currency(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPreview(PlanChangePreview $preview): array
    {
        $dueNow = $preview->dueNowQuote?->totals->gross->minor() ?? 0;
        $net = $preview->proratedNet;
        $credit = $net->isNegative() ? $net->negated()->minor() : 0;

        return [
            'due_now_minor' => $dueNow,
            'credit_minor' => $credit,
            'new_recurring_minor' => $preview->newRecurring->minor(),
            'effective_at' => $preview->effectiveAt->format(DateTimeImmutable::ATOM),
            'credit_delta' => [
                'forfeited' => $preview->creditDelta->forfeited,
                'granted' => $preview->creditDelta->granted,
                'carried' => $preview->creditDelta->carried,
            ],
            'lines' => array_map(
                static fn (ProrationLine $line): array => [
                    'description' => $line->description,
                    'minor' => $line->amount->minor(),
                ],
                $preview->proration->lines,
            ),
        ];
    }
}
