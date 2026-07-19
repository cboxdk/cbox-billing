<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Retention\Contracts\ManagesRetention;
use App\Billing\Retention\Enums\CancellationMode;
use App\Billing\Retention\ValueObjects\CancellationRequest;
use App\Billing\Retirement\PlanRetirementService;
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
use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
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
        private readonly ManagesRetention $retention,
        private readonly PlanRetirementService $retirements,
        private readonly CancellationSurvey $survey,
        private readonly RetentionOffers $offers,
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
            // The sunset notice (ADR-0016) and the retention seam the cancel UI renders from.
            'sunset' => $subscription instanceof Subscription ? $this->retirements->noticeFor($subscription) : null,
            'reasons' => $this->cancellationReasons($session->organization_id, $subscription),
            'offers' => $this->saveOffers($session->organization_id, $subscription),
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

    /**
     * `POST` {at_period_end?, reason?, comment?} — schedule or immediately cancel the
     * subscription, consulting the retention seam: the captured reason flows through the
     * app's {@see ManagesRetention} service, which records it and emits the retention domain
     * events a plugin can react to.
     */
    public function cancel(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'at_period_end' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $session = $this->require($token, SessionType::Portal);
        $subscription = $this->activeSubscription($session->organization_id);

        if (! $subscription instanceof Subscription) {
            return new JsonResponse(['error' => 'No active subscription.'], Response::HTTP_NOT_FOUND);
        }

        $subscription = $this->retention->cancel($subscription, new CancellationRequest(
            mode: $request->boolean('at_period_end', true) ? CancellationMode::PeriodEnd : CancellationMode::Immediate,
            reason: $request->filled('reason') ? $request->string('reason')->toString() : null,
            feedback: $request->filled('comment') ? $request->string('comment')->toString() : null,
        ));

        return new JsonResponse([
            'status' => $subscription->refresh()->standing(),
            'renews_at' => $subscription->cancel_at_period_end
                ? null
                : $subscription->current_period_end?->toIso8601String(),
        ]);
    }

    /**
     * `POST` {plan} — the sunset "pick a successor" choice (ADR-0016): schedule a plan
     * change onto the chosen successor for the period end, so the retirement resolves to
     * that successor at renewal rather than the default.
     */
    public function chooseSuccessor(Request $request, string $token): JsonResponse
    {
        $request->validate(['plan' => ['required', 'string']]);

        $session = $this->require($token, SessionType::Portal);
        $subscription = $this->activeSubscription($session->organization_id);

        if (! $subscription instanceof Subscription) {
            return new JsonResponse(['error' => 'No active subscription.'], Response::HTTP_NOT_FOUND);
        }

        $plan = Plan::query()->with(['prices', 'product'])->where('key', $request->string('plan')->toString())->first();

        if (! $plan instanceof Plan) {
            return new JsonResponse(['error' => 'Unknown plan.'], Response::HTTP_NOT_FOUND);
        }

        $this->retirements->electSuccessor($subscription, $plan);

        return new JsonResponse(['status' => 'scheduled', 'successor' => $plan->name]);
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

        return new JsonResponse(['method' => $this->presentMethod($method), 'methods' => $this->methodsFor($session->organization_id)]);
    }

    /**
     * `POST` {payment_method} — make an already-vaulted method the off-session default. The
     * id is a non-sensitive gateway token — never card data.
     */
    public function setDefaultMethod(Request $request, string $token): JsonResponse
    {
        $request->validate(['payment_method' => ['required', 'string']]);

        $session = $this->require($token, SessionType::Portal);
        $this->gateway->setDefaultPaymentMethod($session->organization_id, $request->string('payment_method')->toString());

        return new JsonResponse(['methods' => $this->methodsFor($session->organization_id)]);
    }

    /**
     * `POST` {payment_method} — detach a vaulted method from the account. The customer keeps
     * whatever methods remain; the gateway owns the vault, so this is a gateway detach.
     */
    public function removeMethod(Request $request, string $token): JsonResponse
    {
        $request->validate(['payment_method' => ['required', 'string']]);

        $session = $this->require($token, SessionType::Portal);
        $this->gateway->detachPaymentMethod($session->organization_id, $request->string('payment_method')->toString());

        return new JsonResponse(['methods' => $this->methodsFor($session->organization_id)]);
    }

    /**
     * The account's vaulted methods in the portal's display shape.
     *
     * @return list<array{id: string, brand: string, last4: string, exp_month: int|null, exp_year: int|null, default: bool}>
     */
    private function methodsFor(string $organizationId): array
    {
        return array_map($this->presentMethod(...), $this->gateway->paymentMethods($organizationId));
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

    /**
     * The churn reasons the bound survey offers, or an empty list when there is no active
     * subscription.
     *
     * @return list<array{key: string, label: string, requires_comment: bool}>
     */
    private function cancellationReasons(string $org, ?Subscription $subscription): array
    {
        if (! $subscription instanceof Subscription) {
            return [];
        }

        return array_map(
            static fn ($reason): array => [
                'key' => $reason->key,
                'label' => $reason->label,
                'requires_comment' => $reason->requiresComment,
            ],
            $this->survey->reasonsFor($org, (string) $subscription->id),
        );
    }

    /**
     * The save-offers the bound seam presents, or an empty list when there is no active
     * subscription.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    private function saveOffers(string $org, ?Subscription $subscription): array
    {
        if (! $subscription instanceof Subscription) {
            return [];
        }

        return array_map(
            static fn ($offer): array => [
                'key' => $offer->key,
                'label' => $offer->label,
                'type' => $offer->type->value,
            ],
            $this->offers->offersFor($org, (string) $subscription->id),
        );
    }

    private function organization(BillingSession $session): Organization
    {
        $organization = Organization::query()->find($session->organization_id);

        return $organization instanceof Organization ? $organization : new Organization(['id' => $session->organization_id]);
    }

    private function activeSubscription(string $org): ?Subscription
    {
        return Subscription::query()
            ->with(['plan.defaultSuccessor', 'plan.prices', 'pendingPlan', 'organization'])
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
