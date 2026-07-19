<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Billing\Account\Contracts\ResolvesAccountCurrency;
use App\Billing\Coupons\CouponDiscounter;
use App\Billing\Coupons\CouponRedeemer;
use App\Billing\Coupons\Exceptions\CouponRedemptionDenied;
use App\Billing\Hosted\Contracts\ManagesBillingSessions;
use App\Billing\Hosted\Enums\SessionType;
use App\Billing\Hosted\PortalBillingHistory;
use App\Billing\Invoicing\InvoicePdfRenderer;
use App\Billing\Notifications\Contracts\ManagesNotificationPreferences;
use App\Billing\Notifications\MailEventType;
use App\Billing\Reporting\UsageReport;
use App\Billing\Retention\Contracts\ManagesRetention;
use App\Billing\Retention\Enums\CancellationMode;
use App\Billing\Retention\ValueObjects\CancellationRequest;
use App\Billing\Retirement\PlanRetirementService;
use App\Billing\Seats\Contracts\ManagesSeats;
use App\Billing\Seats\Exceptions\SeatException;
use App\Billing\Seats\ValueObjects\SeatBreakdown;
use App\Billing\Subscriptions\Contracts\SubscribesOrganizations;
use App\Billing\Subscriptions\ValueObjects\QuantityPreview;
use App\Billing\Support\MoneyFormatter;
use App\Billing\Tax\Exemptions\ExemptionCertificateService;
use App\Billing\Tax\Exemptions\ExemptionJurisdictions;
use App\Billing\Tax\Exemptions\ExemptionType;
use App\Models\BillingSession;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxExemptionCertificate;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Retention\Contracts\CancellationSurvey;
use Cbox\Billing\Retention\Contracts\RetentionOffers;
use Cbox\Billing\Subscription\PlanChange\PlanChangePreview;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        private readonly CouponRedeemer $coupons,
        private readonly CouponDiscounter $discounter,
        private readonly UsageReport $usage,
        private readonly ManagesSeats $seats,
        private readonly PortalBillingHistory $history,
        private readonly ManagesNotificationPreferences $notifications,
        private readonly ExemptionCertificateService $exemptionCertificates,
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
            // Usage & consumption for the current period (same readers the enforcer uses),
            // shown only for a metered plan.
            'usage' => $this->usageMeters($organization),
            // Purchased + assigned seats (only for a seat-driving subscription).
            'seats' => $subscription instanceof Subscription ? $this->seats->breakdown($subscription) : null,
            // The broad billing history (invoices, receipts, credit notes, coupons).
            'history' => $this->history->forOrganization($session->organization_id),
            // Optional-vs-mandatory notification preferences.
            'notifications' => $this->notificationSettings($session->organization_id),
            // Tax exemption certificates the customer has on file (self-serve upload + status).
            'exemptions' => TaxExemptionCertificate::query()
                ->where('organization_id', $session->organization_id)
                ->orderByDesc('created_at')
                ->get(),
            'exemptionTypes' => ExemptionType::cases(),
            'jurisdictionOptions' => ExemptionJurisdictions::options(),
        ]);
    }

    /**
     * `POST` — the customer uploads an exemption certificate for their own account. It lands
     * `pending` for operator review (the customer cannot self-verify) and the document is
     * stored on the private disk. Scoped strictly to the token's organization.
     */
    public function uploadExemption(Request $request, string $token): RedirectResponse
    {
        $session = $this->require($token, SessionType::Portal);
        $organization = $this->organization($session);

        $request->validate([
            'jurisdiction' => ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || ! ExemptionJurisdictions::isValid($value)) {
                    $fail('Choose a supported jurisdiction.');
                }
            }],
            'exemption_type' => ['required', 'string', 'in:'.implode(',', ExemptionType::values())],
            'certificate_number' => ['required', 'string', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $type = ExemptionType::from($request->string('exemption_type')->toString());
        $number = $request->string('certificate_number')->toString();

        if (! $type->acceptsCertificateNumber($number)) {
            return back()->withInput()->withErrors([
                'certificate_number' => sprintf('That does not look like a valid %s certificate number.', $type->label()),
            ]);
        }

        $this->exemptionCertificates->capture($organization, [
            'jurisdiction' => $request->string('jurisdiction')->toString(),
            'exemption_type' => $type->value,
            'certificate_number' => $number,
            'expires_at' => $request->filled('expires_at') ? $request->string('expires_at')->toString() : null,
        ], $request->file('document'));

        return redirect()
            ->route('hosted.portal.show', $token)
            ->with('status', 'Exemption certificate uploaded — our team will review it shortly.');
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

        [$coupon, $couponError] = $this->resolveCoupon($request, $session, $plan);

        if ($couponError instanceof JsonResponse) {
            return $couponError;
        }

        return new JsonResponse($this->presentPreview($this->subscriptions->previewChange($subscription, $plan), $coupon));
    }

    /** `POST` — apply the plan change (the same consequence {@see preview()} reported). */
    public function change(Request $request, string $token): JsonResponse
    {
        [$session, $subscription, $plan, $error] = $this->resolveChange($request, $token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        [$coupon, $couponError] = $this->resolveCoupon($request, $session, $plan);

        if ($couponError instanceof JsonResponse) {
            return $couponError;
        }

        $preview = $this->subscriptions->changePlan($subscription, $plan);

        // Bind the promo code to the (now-changed) subscription so its renewals are
        // discounted (repeating/forever); the immediate proration is charged in full.
        if ($coupon instanceof Coupon) {
            $this->coupons->redeem($coupon, $subscription);
        }

        return new JsonResponse($this->presentPreview($preview, $coupon));
    }

    /**
     * Validate the optional promo code on a plan change against the TARGET plan, in the
     * account's currency (deny-by-default → 422). Returns `[coupon|null, errorResponse|null]`.
     *
     * @return array{0: Coupon|null, 1: JsonResponse|null}
     */
    private function resolveCoupon(Request $request, BillingSession $session, Plan $plan): array
    {
        if (! $request->filled('coupon')) {
            return [null, null];
        }

        $currency = $this->currencies->for($this->organization($session));

        try {
            $coupon = $this->coupons->validate(
                $request->string('coupon')->toString(),
                $plan,
                $currency,
                $session->organization_id,
            );
        } catch (CouponRedemptionDenied $e) {
            return [null, new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY)];
        }

        return [$coupon, null];
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
     * `POST` {seats} — the prorated consequence of buying/releasing to `{seats}` purchased Full
     * seats, WITHOUT applying it (preview == charge). Guardrailed exactly as the change is: a
     * count below one or below the assigned members is refused (422) before any preview.
     */
    public function seatPreview(Request $request, string $token): JsonResponse
    {
        [$subscription, $seats, $error] = $this->resolveSeatChange($request, $token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        try {
            $preview = $this->seats->previewPurchased($subscription, $seats);
        } catch (SeatException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->presentSeatPreview($preview));
    }

    /**
     * `POST` {seats} — buy/release purchased Full seats: raises/lowers the billed quantity
     * through the engine's prorated `changeQuantity` (the H6 proration is charged/credited by
     * the same collector the console + API use). Returns the refreshed seat breakdown.
     */
    public function setSeats(Request $request, string $token): JsonResponse
    {
        [$subscription, $seats, $error] = $this->resolveSeatChange($request, $token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        try {
            $this->seats->setPurchased($subscription, $seats);
        } catch (SeatException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->presentSeats($subscription->refresh()));
    }

    /**
     * `POST` {subject} — hand a free purchased seat to an eligible member of THIS org (Full).
     * Cap-enforced and eligibility-gated in the service; a refusal ("buy more seats" / not
     * eligible) surfaces as a 422 with the guardrail message.
     */
    public function assignSeat(Request $request, string $token): JsonResponse
    {
        $request->validate(['subject' => ['required', 'string']]);

        [$subscription, $error] = $this->resolveSubscription($token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        try {
            $this->seats->assign($subscription, $request->string('subject')->toString());
        } catch (SeatException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->presentSeats($subscription));
    }

    /** `POST` {subject} — free a member's seat (they become Light); the purchased count is unchanged. */
    public function unassignSeat(Request $request, string $token): JsonResponse
    {
        $request->validate(['subject' => ['required', 'string']]);

        [$subscription, $error] = $this->resolveSubscription($token);

        if ($error instanceof JsonResponse) {
            return $error;
        }

        $this->seats->unassign($subscription->organization_id, $request->string('subject')->toString());

        return new JsonResponse($this->presentSeats($subscription));
    }

    /**
     * `POST` {event, opted_in} — flip one OPTIONAL notification for this org. A mandatory/legal
     * event is refused (422): those can never be switched off. Returns the refreshed settings.
     */
    public function updateNotifications(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'event' => ['required', 'string'],
            'opted_in' => ['required', 'boolean'],
        ]);

        $session = $this->require($token, SessionType::Portal);
        $event = MailEventType::tryFrom($request->string('event')->toString());

        if (! $event instanceof MailEventType || ! $event->isOptional()) {
            return new JsonResponse(['error' => 'This notification cannot be changed.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->notifications->setOptedIn($session->organization_id, $event, $request->boolean('opted_in'));

        return new JsonResponse(['optional' => $this->notifications->snapshot($session->organization_id)]);
    }

    /**
     * Resolve the session's active subscription for a seat action (scoped to the token's org).
     * On no active subscription, slot 0 is a placeholder and slot 1 is a ready 404 — so callers
     * guard on the error and can treat slot 0 as a real subscription once past the guard.
     *
     * @return array{0: Subscription, 1: JsonResponse|null}
     */
    private function resolveSubscription(string $token): array
    {
        $session = $this->require($token, SessionType::Portal);
        $subscription = $this->activeSubscription($session->organization_id);

        if (! $subscription instanceof Subscription) {
            return [new Subscription, new JsonResponse(['error' => 'No active subscription.'], Response::HTTP_NOT_FOUND)];
        }

        return [$subscription, null];
    }

    /**
     * Resolve `[subscription, seats, error]` for a seat buy/release/preview — the seats input is
     * validated and the session's active subscription resolved (scoped to the token's org).
     *
     * @return array{0: Subscription, 1: int, 2: JsonResponse|null}
     */
    private function resolveSeatChange(Request $request, string $token): array
    {
        $request->validate(['seats' => ['required', 'integer', 'min:1']]);

        [$subscription, $error] = $this->resolveSubscription($token);

        if ($error instanceof JsonResponse) {
            return [new Subscription, 0, $error];
        }

        return [$subscription, $request->integer('seats'), null];
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
        $request->validate([
            'plan' => ['required', 'string'],
            'coupon' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

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
    private function presentPreview(PlanChangePreview $preview, ?Coupon $coupon = null): array
    {
        $currency = $preview->newRecurring->currency();
        $dueNowMinor = $preview->dueNowQuote?->totals->gross->minor() ?? 0;

        return [
            'due_now_minor' => $dueNowMinor,
            // Preformatted server-side through the single money seam, so the client never
            // re-derives an amount with a hardcoded /100 + locale (wrong for JPY/ISK & co.).
            'due_now' => MoneyFormatter::minor($dueNowMinor, $currency),
            'new_recurring_minor' => $preview->newRecurring->minor(),
            'new_recurring' => MoneyFormatter::money($preview->newRecurring),
            'currency' => $currency,
            'effective_at' => $preview->effectiveAt->format(DateTimeImmutable::ATOM),
            'coupon' => $this->presentPreviewCoupon($preview, $coupon),
        ];
    }

    /**
     * The promo block on a plan-change preview: the recurring net after the coupon (through
     * the engine applier) — what renewals of the new plan will bill. Null when no code.
     *
     * @return array<string, mixed>|null
     */
    private function presentPreviewCoupon(PlanChangePreview $preview, ?Coupon $coupon): ?array
    {
        if (! $coupon instanceof Coupon) {
            return null;
        }

        $discount = $this->discounter->forCoupon($coupon, $preview->newRecurring, Carbon::now()->toDateTimeImmutable());
        $discounted = $discount === null ? $preview->newRecurring : $discount->discounted;

        return [
            'code' => $coupon->code,
            'duration' => $coupon->duration,
            'new_recurring_minor' => $discounted->minor(),
            'new_recurring' => MoneyFormatter::money($discounted),
            'discount_minor' => $discount === null ? 0 : $discount->amount->minor(),
        ];
    }

    /**
     * The current-period usage-against-allowance for the org, filtered to the ENABLED metered
     * dimensions — the same {@see UsageReport} the console renders and the enforcement path
     * reads (one source of truth). Returns null for a flat/un-metered plan so the whole section
     * is hidden rather than shown empty.
     *
     * @return array<string, mixed>|null
     */
    private function usageMeters(Organization $organization): ?array
    {
        $report = $this->usage->forOrganization($organization);
        $meters = $report['meters'] ?? [];

        if (! is_array($meters)) {
            return null;
        }

        $metered = [];

        foreach ($meters as $meter) {
            if (is_array($meter) && ($meter['enabled'] ?? false)) {
                $metered[] = $meter;
            }
        }

        if ($metered === []) {
            return null;
        }

        return [
            'period_start' => is_string($report['period_start'] ?? null) ? $report['period_start'] : '',
            'period_end' => is_string($report['period_end'] ?? null) ? $report['period_end'] : '',
            'meters' => $metered,
        ];
    }

    /**
     * The seat breakdown in the portal's JSON/display shape.
     *
     * @return array<string, mixed>
     */
    private function presentSeats(Subscription $subscription): array
    {
        return $this->seatShape($this->seats->breakdown($subscription));
    }

    /** @return array<string, mixed> */
    private function seatShape(SeatBreakdown $breakdown): array
    {
        return [
            'purchased' => $breakdown->purchased,
            'assigned' => $breakdown->assigned,
            'free' => $breakdown->free(),
            'full_count' => $breakdown->fullCount(),
            'light_count' => $breakdown->lightCount(),
            'full' => $breakdown->full,
            'light' => $breakdown->light,
            'assignable' => $breakdown->assignable,
        ];
    }

    /**
     * The prorated seat-change preview: the amount due now on a buy (a reduction credits and
     * owes nothing now), server-preformatted through the single money seam.
     *
     * @return array<string, mixed>
     */
    private function presentSeatPreview(QuantityPreview $preview): array
    {
        $currency = $preview->charge->currency();
        $credit = $preview->isCredit();
        $dueNowMinor = $credit ? 0 : $preview->charge->minor();

        return [
            'from_seats' => $preview->fromSeats,
            'to_seats' => $preview->toSeats,
            'is_credit' => $credit,
            'due_now_minor' => $dueNowMinor,
            'due_now' => MoneyFormatter::minor($dueNowMinor, $currency),
            // The signed proration (negative when a reduction credits the wallet).
            'charge' => MoneyFormatter::money($preview->charge),
            'currency' => $currency,
        ];
    }

    /**
     * The org's notification settings for the portal: the optional toggles (with their current
     * opt-in state) split from the mandatory always-on mails, both sourced from the single
     * {@see MailEventType} classification so the two lists can never drift.
     *
     * @return array{optional: list<array<string, mixed>>, mandatory: list<array{label: string, description: string}>}
     */
    private function notificationSettings(string $org): array
    {
        $snapshot = $this->notifications->snapshot($org);

        $optional = [];

        foreach (MailEventType::optional() as $event) {
            $optional[] = [
                'event' => $event->value,
                'label' => $event->label(),
                'description' => $event->description(),
                'opted_in' => $snapshot[$event->value] ?? true,
            ];
        }

        $mandatory = [];

        foreach (MailEventType::mandatory() as $event) {
            $mandatory[] = ['label' => $event->label(), 'description' => $event->description()];
        }

        return ['optional' => $optional, 'mandatory' => $mandatory];
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
