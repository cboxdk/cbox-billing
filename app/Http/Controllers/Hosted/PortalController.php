<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Support\MoneyFormatter;
use App\Models\BillingSession;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The hosted customer-portal page and its actions (ADR-0009 Path A), authorized solely by
 * the session token. It reads the org's current subscription, the plans it can move to,
 * its invoices, and its saved payment methods; and it lets the customer change plan (with
 * a preview), cancel, and update its payment method by confirming a SetupIntent on the
 * gateway's element.
 *
 * Thin: every write delegates to the SAME lifecycle services the management API drives
 * ({@see SubscribesOrganizations}) and to the bound {@see PaymentGateway} — the controller
 * only resolves the session, validates, and maps the result.
 */
class PortalController extends HostedController
{
    public function __construct(
        ManagesBillingSessions $sessions,
        private readonly SubscribesOrganizations $subscriptions,
        private readonly PaymentGateway $gateway,
        private readonly ResolvesAccountCurrency $currencies,
    ) {
        parent::__construct($sessions);
    }

    /** The portal page: current subscription, upgrade/downgrade options, invoices, methods. */
    public function show(string $token): View
    {
        $session = $this->require($token, SessionType::Portal);
        $organization = $this->organization($session);
        $currency = $this->currencies->for($organization);
        $subscription = $this->activeSubscription($session->organization_id);

        return view('hosted.portal', [
            'session' => $session,
            'organization' => $organization,
            'currency' => $currency,
            'subscription' => $subscription,
            'plans' => $this->availablePlans($currency, $subscription),
            'invoices' => $this->invoices($session->organization_id),
            'methods' => $this->gateway->paymentMethods($organization->id),
        ]);
    }

    /**
     * `GET` — download an invoice for this account as a PDF. Per-session org scope: an
     * invoice not owned by the session's organization is 404 (deny-by-default, never leaks
     * that another org's invoice exists).
     */
    public function invoicePdf(string $token, Invoice $invoice, InvoicePdfRenderer $renderer): Response
    {
        $session = $this->require($token, SessionType::Portal);

        if ($invoice->organization_id !== $session->organization_id) {
            throw new NotFoundHttpException('This invoice is not available.');
        }

        return new Response($renderer->render($invoice), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename($invoice).'"',
        ]);
    }

    /** `POST` — the confirmable consequence of moving to `{plan}`, without applying it. */
    public function preview(Request $request, string $token): JsonResponse
    {
        [$session, $subscription, $plan, $error] = $this->resolveChange($request, $token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        return new JsonResponse($this->presentPreview($this->subscriptions->previewChange($subscription, $plan)));
    }

    /** `POST` — apply the plan change (the same consequence {@see preview()} reported). */
    public function change(Request $request, string $token): JsonResponse
    {
        [$session, $subscription, $plan, $error] = $this->resolveChange($request, $token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        return new JsonResponse($this->presentPreview($this->subscriptions->changePlan($subscription, $plan)));
    }

    /** `POST` {at_period_end?} — schedule or immediately cancel the subscription. */
    public function cancel(Request $request, string $token): JsonResponse
    {
        $request->validate(['at_period_end' => ['sometimes', 'boolean']]);

        $session = $this->require($token, SessionType::Portal);
        $subscription = $this->activeSubscription($session->organization_id);

        if (! $subscription instanceof Subscription) {
            return new JsonResponse(['error' => 'No active subscription.'], Response::HTTP_NOT_FOUND);
        }

        $subscription = $this->subscriptions->cancel($subscription, $request->boolean('at_period_end', true));

        return new JsonResponse([
            'status' => $subscription->refresh()->standing(),
            'renews_at' => $subscription->cancel_at_period_end
                ? null
                : $subscription->current_period_end?->toIso8601String(),
        ]);
    }

    /**
     * `POST` — create a SetupIntent so the customer can vault a new payment method for
     * off-session renewals. Returns the element mount data; no charge is made.
     */
    public function setupIntent(string $token): JsonResponse
    {
        $session = $this->require($token, SessionType::Portal);

        $result = $this->gateway->createSetupIntent(new SetupIntentRequest(
            account: $session->organization_id,
            idempotencyKey: 'seti_'.Str::random(24),
        ));

        return new JsonResponse([
            'gateway' => $result->gateway,
            'publishable_key' => $result->publishableKey,
            'client_secret' => $result->clientSecret,
            'status' => $result->status->value,
        ]);
    }

    /**
     * `POST` {payment_method} — attach the method the gateway vaulted when its element
     * confirmed the SetupIntent, and make it the account default off-session method. The
     * payment-method id is a non-sensitive gateway token — never card data.
     */
    public function paymentMethod(Request $request, string $token): JsonResponse
    {
        $request->validate(['payment_method' => ['required', 'string']]);

        $session = $this->require($token, SessionType::Portal);
        $paymentMethodId = $request->string('payment_method')->toString();

        $method = $this->gateway->attachPaymentMethod($session->organization_id, $paymentMethodId);
        $this->gateway->setDefaultPaymentMethod($session->organization_id, $paymentMethodId);

        return new JsonResponse(['method' => $this->presentMethod($method)]);
    }

    /**
     * Resolve the session, its active subscription, and the requested target plan for a
     * preview/change; the fourth element is a ready error response when any is missing.
     *
     * @return array{0: BillingSession, 1: Subscription, 2: Plan, 3: JsonResponse|null}
     */
    private function resolveChange(Request $request, string $token): array
    {
        $request->validate(['plan' => ['required', 'string']]);

        $session = $this->require($token, SessionType::Portal);
        $subscription = $this->activeSubscription($session->organization_id);
        $plan = Plan::query()->with(['prices', 'product'])->where('key', $request->string('plan')->toString())->first();

        if (! $subscription instanceof Subscription) {
            return [$session, new Subscription, new Plan, new JsonResponse(['error' => 'No active subscription.'], Response::HTTP_NOT_FOUND)];
        }

        if (! $plan instanceof Plan) {
            return [$session, $subscription, new Plan, new JsonResponse(['error' => 'Unknown plan.'], Response::HTTP_NOT_FOUND)];
        }

        return [$session, $subscription, $plan, null];
    }

    private function organization(BillingSession $session): Organization
    {
        $organization = Organization::query()->find($session->organization_id);

        return $organization instanceof Organization ? $organization : new Organization(['id' => $session->organization_id]);
    }

    private function activeSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with(['plan', 'pendingPlan'])
            ->where('organization_id', $org)
            ->where('status', 'active')
            ->latest('current_period_start')
            ->first();
    }

    /**
     * The active plans the org can move to, priced in its currency (excluding its current
     * plan). A plan not priced in the currency is omitted, deny-by-default.
     *
     * @return list<array{key: string, name: string, price: string, minor: int}>
     */
    private function availablePlans(string $currency, ?Subscription $subscription): array
    {
        $currentKey = $subscription?->plan?->key;

        return array_values(Plan::query()
            ->with('prices')
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Plan $plan): bool => $plan->key !== $currentKey && $plan->prices->contains('currency', $currency))
            ->map(static function (Plan $plan) use ($currency): array {
                $price = $plan->priceFor($currency);

                return [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price' => MoneyFormatter::money($price),
                    'minor' => $price->minor(),
                ];
            })
            ->all());
    }

    /** @return Collection<int, Invoice> */
    private function invoices(string $org): Collection
    {
        return Invoice::query()
            ->where('organization_id', $org)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get();
    }

    /** @return array<string, mixed> */
    private function presentPreview(PlanChangePreview $preview): array
    {
        return [
            'due_now_minor' => $preview->dueNowQuote?->totals->gross->minor() ?? 0,
            'new_recurring_minor' => $preview->newRecurring->minor(),
            'currency' => $preview->newRecurring->currency(),
            'effective_at' => $preview->effectiveAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array{id: string, brand: string, last4: string, exp_month: int|null, exp_year: int|null, default: bool} */
    private function presentMethod(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'brand' => $method->brand,
            'last4' => $method->last4,
            'exp_month' => $method->expMonth,
            'exp_year' => $method->expYear,
            'default' => $method->isDefault,
        ];
    }
}
