<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Management;

use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Http\Controllers\Api\ApiController;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use Cbox\Billing\Subscription\Proration\ProrationLine;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The subscription lifecycle surface of the management API — thin HTTP over
 * {@see SubscribesOrganizations} (task #41's service): read the current subscription,
 * subscribe, preview a plan change, apply it, and cancel. Every write is per-org scoped
 * (a token for org A cannot touch org B → 403) and delegates the engine work; the
 * controller only validates, authorizes, and maps.
 */
class SubscriptionController extends ApiController
{
    /** `GET /api/v1/subscriptions/{org}` — the org's current subscription, or 404. */
    public function show(Request $request, string $org): JsonResponse
    {
        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        return new JsonResponse($this->present($subscription));
    }

    /** `POST /api/v1/subscriptions` {org, plan} — subscribe the org to a plan. */
    public function store(Request $request, SubscribesOrganizations $subscriptions): JsonResponse
    {
        $request->validate([
            'org' => ['required', 'string'],
            'plan' => ['required', 'string'],
            'seats' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $org = $request->string('org')->toString();

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $organization = Organization::query()->find($org);

        if (! $organization instanceof Organization) {
            return $this->notFound('Unknown organization.');
        }

        $plan = $this->planByKey($request->string('plan')->toString());

        if (! $plan instanceof Plan) {
            return $this->notFound('Unknown plan.');
        }

        $currency = $request->has('currency') ? strtoupper($request->string('currency')->toString()) : null;

        $subscription = $subscriptions->subscribe(
            $organization,
            $plan,
            $request->integer('seats', 1),
            $currency,
        );

        return new JsonResponse([
            'subscription' => $this->present($subscription),
            // The manual gateway settles out of band, so there is no client-confirmable
            // intent yet; the field is reserved for the payment-intents task.
            'payment_intent' => null,
        ], Response::HTTP_CREATED);
    }

    /** `POST /api/v1/subscriptions/{org}/preview` {plan} — the consequence of a change, uncommitted. */
    public function preview(Request $request, string $org, SubscribesOrganizations $subscriptions): JsonResponse
    {
        return $this->planChange($request, $org, $subscriptions, apply: false);
    }

    /** `POST /api/v1/subscriptions/{org}/change` {plan} — apply the change (same consequence as preview). */
    public function change(Request $request, string $org, SubscribesOrganizations $subscriptions): JsonResponse
    {
        return $this->planChange($request, $org, $subscriptions, apply: true);
    }

    /** `POST /api/v1/subscriptions/{org}/cancel` {at_period_end?} — schedule or immediately cancel. */
    public function cancel(Request $request, string $org, SubscribesOrganizations $subscriptions): JsonResponse
    {
        $request->validate(['at_period_end' => ['sometimes', 'boolean']]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        // Default to a graceful period-end cancellation; an explicit `false` cancels now
        // and drives the engine's forfeiture-on-transition.
        $atPeriodEnd = $request->boolean('at_period_end', true);

        $subscription = $subscriptions->cancel($subscription, $atPeriodEnd);

        return new JsonResponse($this->present($subscription->refresh()));
    }

    private function planChange(Request $request, string $org, SubscribesOrganizations $subscriptions, bool $apply): JsonResponse
    {
        $request->validate(['plan' => ['required', 'string']]);

        if ($denied = $this->denyUnlessMayActFor($request, $org)) {
            return $denied;
        }

        $subscription = $this->activeSubscription($org);

        if (! $subscription instanceof Subscription) {
            return $this->notFound('This organization has no active subscription.');
        }

        $newPlan = $this->planByKey($request->string('plan')->toString());

        if (! $newPlan instanceof Plan) {
            return $this->notFound('Unknown plan.');
        }

        $preview = $apply
            ? $subscriptions->changePlan($subscription, $newPlan)
            : $subscriptions->previewChange($subscription, $newPlan);

        return new JsonResponse($this->presentPreview($preview));
    }

    private function activeSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('organization_id', $org)
            ->where('status', 'active')
            ->latest('current_period_start')
            ->first();
    }

    private function planByKey(string $key): ?Plan
    {
        return Plan::query()->with(['prices', 'product'])->where('key', $key)->first();
    }

    /** @return array<string, mixed> */
    private function present(Subscription $subscription): array
    {
        return [
            'plan' => $subscription->plan?->key,
            'status' => $subscription->status->value,
            'period_start' => $subscription->current_period_start?->toIso8601String(),
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'renews_at' => $subscription->cancel_at_period_end
                ? null
                : $subscription->current_period_end?->toIso8601String(),
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
            'lines' => array_map(
                static fn (ProrationLine $line): array => [
                    'description' => $line->description,
                    'minor' => $line->amount->minor(),
                ],
                $preview->proration->lines,
            ),
        ];
    }

    private function notFound(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
    }
}
