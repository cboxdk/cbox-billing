<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\CurrentUser;
use App\Billing\Cpq\Enums\QuoteDiscountKind;
use App\Billing\Cpq\Enums\QuoteLineType;
use App\Billing\Cpq\Exceptions\QuoteActionDenied;
use App\Billing\Cpq\QuoteApprovalRouter;
use App\Billing\Cpq\QuoteAuthoring;
use App\Billing\Cpq\QuoteCalculator;
use App\Billing\Cpq\QuoteLifecycle;
use App\Billing\Cpq\QuoteReport;
use App\Billing\Cpq\ValueObjects\QuoteDraft;
use App\Billing\Cpq\ValueObjects\QuoteLineDraft;
use App\Billing\Cpq\ValueObjects\QuoteTermsDraft;
use App\Models\Coupon;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\SellerEntity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Quotes console (CPQ Wave 5) — thin HTTP over the CPQ services. A rep authors a draft
 * ({@see QuoteAuthoring}), submits it (threshold-routed by {@see QuoteApprovalRouter}), sends it
 * ({@see QuoteLifecycle}), and tracks it through the lifecycle. Reads carry `quotes:read`, writes
 * `quotes:manage`; the approval step carries `quotes:approve` (see {@see QuoteApprovalController}).
 * Every total shown is computed through the engine by {@see QuoteCalculator} (preview == charge).
 */
class QuoteController extends Controller
{
    public function index(Request $request, QuoteReport $report): View
    {
        $tab = $request->string('status')->toString() ?: 'all';

        return view('billing.quotes.index', [
            'activeArea' => 'quotes',
            'activeNav' => $tab === 'all' ? 'all' : $tab,
            'quotes' => $report->paginate($tab === 'all' ? null : $tab, $request->string('q')->toString() ?: null),
            'counts' => $report->counts(),
            'tab' => $tab,
            'search' => $request->string('q')->toString(),
        ]);
    }

    public function create(): View
    {
        return view('billing.quotes.form', $this->formPayload(null));
    }

    public function store(Request $request, QuoteAuthoring $authoring, CurrentUser $current): RedirectResponse
    {
        $this->validateQuote($request);

        try {
            $quote = $authoring->create($this->draft($request, $current));
        } catch (QuoteActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.quotes.show', $quote->id)
            ->with('status', sprintf('Quote %s created.', $quote->number));
    }

    public function show(Quote $quote, QuoteCalculator $calculator, QuoteApprovalRouter $approvals): View
    {
        $quote->loadMissing(['lines.plan', 'organization', 'coupon', 'sellerEntity', 'subscription.plan', 'acceptance']);

        return view('billing.quotes.show', [
            'activeArea' => 'quotes',
            'activeNav' => $quote->status->value,
            'quote' => $quote,
            'computation' => $calculator->compute($quote),
            'threshold' => $approvals->thresholdSummary(),
        ]);
    }

    public function edit(Quote $quote): View|RedirectResponse
    {
        if (! $quote->isDraft()) {
            return redirect()->route('billing.quotes.show', $quote->id)
                ->with('error', 'Only a draft quote can be edited.');
        }

        $quote->loadMissing('lines.plan');

        return view('billing.quotes.form', $this->formPayload($quote));
    }

    public function update(Request $request, Quote $quote, QuoteAuthoring $authoring, CurrentUser $current): RedirectResponse
    {
        $this->validateQuote($request);

        try {
            $authoring->update($quote, $this->draft($request, $current));
        } catch (QuoteActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('billing.quotes.show', $quote->id)
            ->with('status', sprintf('Quote %s updated.', $quote->number));
    }

    public function submit(Quote $quote, QuoteCalculator $calculator, QuoteApprovalRouter $approvals): RedirectResponse
    {
        try {
            $approvals->submit($quote, $calculator->compute($quote));
        } catch (QuoteActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $quote->status->isPendingApproval()
            ? sprintf('Quote %s submitted for approval.', $quote->number)
            : sprintf('Quote %s approved and ready to send.', $quote->number);

        return redirect()->route('billing.quotes.show', $quote->id)->with('status', $message);
    }

    public function send(Quote $quote, QuoteLifecycle $lifecycle): RedirectResponse
    {
        return $this->run($quote, fn () => $lifecycle->send($quote), sprintf('Quote %s sent — the order form is live.', $quote->number));
    }

    public function resend(Quote $quote, QuoteLifecycle $lifecycle): RedirectResponse
    {
        return $this->run($quote, fn () => $lifecycle->resend($quote), sprintf('Quote %s re-sent.', $quote->number));
    }

    public function expire(Quote $quote, QuoteLifecycle $lifecycle): RedirectResponse
    {
        return $this->run($quote, fn () => $lifecycle->expire($quote), sprintf('Quote %s expired.', $quote->number));
    }

    public function clone(Quote $quote, QuoteLifecycle $lifecycle): RedirectResponse
    {
        $copy = $lifecycle->clone($quote);

        return redirect()->route('billing.quotes.show', $copy->id)
            ->with('status', sprintf('Cloned %s into %s.', $quote->number, $copy->number));
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        if ($quote->isProvisioned()) {
            return back()->with('error', 'A provisioned quote cannot be deleted.');
        }

        $number = $quote->number;
        $quote->delete();

        return redirect()->route('billing.quotes')->with('status', sprintf('Quote %s deleted.', $number));
    }

    /**
     * @param  callable(): mixed  $action
     */
    private function run(Quote $quote, callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (QuoteActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        $redirect = redirect()->route('billing.quotes.show', $quote->id)->with('status', $success);

        // The order-form token is stored only as a SHA-256 digest, so the shareable link can only
        // be shown at the moment it is minted (send/resend) — the plaintext is held in memory on the
        // quote for exactly this request. Flash it once so the operator can copy it; a later reload
        // of the quote cannot reconstruct it from the row.
        if (is_string($quote->token) && $quote->token !== '') {
            $redirect->with('order_form_url', route('quote.show', $quote->token));
        }

        return $redirect;
    }

    private function validateQuote(Request $request): void
    {
        $request->validate([
            'organization_id' => ['nullable', 'string', 'exists:organizations,id'],
            'prospect_name' => ['nullable', 'string', 'max:200'],
            'prospect_email' => ['nullable', 'email', 'max:200'],
            'seller_entity_id' => ['nullable', 'string', 'exists:seller_entities,id'],
            'currency' => ['required', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'coupon_id' => ['nullable', 'integer', 'exists:coupons,id'],
            'owner_name' => ['nullable', 'string', 'max:200'],

            'term_count' => ['required', 'integer', 'min:1', 'max:120'],
            'term_unit' => ['required', 'in:day,month,year'],
            'billing_interval' => ['required', 'in:monthly,yearly'],
            'start_date' => ['nullable', 'date'],
            'minimum_commitment' => ['nullable', 'numeric', 'min:0'],
            'ramp' => ['nullable', 'array'],
            'ramp.*.from_period_index' => ['required_with:ramp', 'integer', 'min:0'],
            'ramp.*.amount' => ['required_with:ramp', 'numeric', 'min:0'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.type' => ['required', 'in:plan,custom'],
            'lines.*.plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_kind' => ['nullable', 'in:percent,fixed'],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.recurring' => ['nullable', 'boolean'],
        ]);
    }

    private function draft(Request $request, CurrentUser $current): QuoteDraft
    {
        $currency = strtoupper($request->string('currency')->toString());
        $operator = $current->user();

        return new QuoteDraft(
            organizationId: $request->filled('organization_id') ? $request->string('organization_id')->toString() : null,
            prospectName: $request->filled('prospect_name') ? $request->string('prospect_name')->toString() : null,
            prospectEmail: $request->filled('prospect_email') ? $request->string('prospect_email')->toString() : null,
            sellerEntityId: $request->filled('seller_entity_id') ? $request->string('seller_entity_id')->toString() : null,
            currency: $currency,
            validUntil: $request->filled('valid_until') ? $request->string('valid_until')->toString() : null,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            couponId: $request->filled('coupon_id') ? $request->integer('coupon_id') : null,
            ownerSub: $operator?->sub,
            ownerName: $request->filled('owner_name') ? $request->string('owner_name')->toString() : $operator?->name,
            terms: new QuoteTermsDraft(
                termCount: $request->integer('term_count'),
                termUnit: $request->string('term_unit')->toString(),
                billingInterval: $request->string('billing_interval')->toString(),
                startDate: $request->filled('start_date') ? $request->string('start_date')->toString() : null,
                minimumCommitmentMinor: $request->filled('minimum_commitment') ? $this->toMinor($request->input('minimum_commitment')) : null,
                ramp: $this->rampSteps($request, $currency),
            ),
            lines: $this->lines($request, $currency),
        );
    }

    /**
     * @return list<QuoteLineDraft>
     */
    private function lines(Request $request, string $currency): array
    {
        $raw = $request->input('lines');
        $lines = [];

        if (! is_array($raw)) {
            return [];
        }

        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = QuoteLineType::from(is_string($row['type'] ?? null) ? $row['type'] : 'custom');
            $discountKind = isset($row['discount_kind']) && is_string($row['discount_kind']) && $row['discount_kind'] !== ''
                ? QuoteDiscountKind::from($row['discount_kind'])
                : null;

            $discountValue = null;
            if ($discountKind !== null && isset($row['discount_value']) && is_numeric($row['discount_value'])) {
                $discountValue = $discountKind === QuoteDiscountKind::Percent
                    ? (int) $row['discount_value']
                    : $this->toMinor($row['discount_value']);
            }

            $lines[] = new QuoteLineDraft(
                type: $type,
                planId: $type === QuoteLineType::Plan && isset($row['plan_id']) && is_numeric($row['plan_id']) ? (int) $row['plan_id'] : null,
                description: isset($row['description']) && is_string($row['description']) && $row['description'] !== '' ? $row['description'] : null,
                quantity: isset($row['quantity']) && is_numeric($row['quantity']) ? max(1, (int) $row['quantity']) : 1,
                unitAmountMinor: $type === QuoteLineType::Custom && isset($row['unit_amount']) && is_numeric($row['unit_amount']) ? $this->toMinor($row['unit_amount']) : null,
                discountKind: $discountKind,
                discountValue: $discountValue,
                // A plan line defaults to recurring; a custom line is a one-off unless flagged.
                recurring: $type === QuoteLineType::Plan
                    ? true
                    : (isset($row['recurring']) && (bool) $row['recurring']),
            );
        }

        return $lines;
    }

    /**
     * @return list<array{from_period_index: int, amount_minor: int}>|null
     */
    private function rampSteps(Request $request, string $currency): ?array
    {
        $raw = $request->input('ramp');

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $steps = [];

        foreach ($raw as $row) {
            if (! is_array($row) || ! isset($row['amount']) || ! is_numeric($row['amount'])) {
                continue;
            }

            $steps[] = [
                'from_period_index' => isset($row['from_period_index']) && is_numeric($row['from_period_index']) ? (int) $row['from_period_index'] : 0,
                'amount_minor' => $this->toMinor($row['amount']),
            ];
        }

        return $steps === [] ? null : $steps;
    }

    /** Parse a major-unit decimal (e.g. "1234.56") into integer minor units, exactly. */
    private function toMinor(mixed $value): int
    {
        if (is_string($value)) {
            $string = trim($value);
        } elseif (is_int($value) || is_float($value)) {
            $string = (string) $value;
        } else {
            return 0;
        }

        if ($string === '' || ! is_numeric($string)) {
            return 0;
        }

        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '+-');
        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $minor = (int) $whole * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(?Quote $quote): array
    {
        $plans = Plan::query()->with('prices')->where('active', true)->orderBy('name')->get();

        $currencies = [];
        foreach ($plans as $plan) {
            foreach ($plan->prices as $price) {
                $currencies[$price->currency] = true;
            }
        }
        $currencyList = array_keys($currencies);
        sort($currencyList);
        if ($currencyList === []) {
            $default = config('billing.default_currency');
            $currencyList = [is_string($default) ? $default : 'DKK'];
        }

        return [
            'activeArea' => 'quotes',
            'activeNav' => $quote !== null ? 'all' : 'new',
            'quote' => $quote,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'billing_currency', 'billing_country']),
            'plans' => $plans,
            'coupons' => Coupon::query()->where('active', true)->orderBy('code')->get(['id', 'code']),
            'sellers' => SellerEntity::query()->whereNull('archived_at')->orderBy('legal_name')->get(['id', 'legal_name']),
            'currencies' => $currencyList,
        ];
    }
}
