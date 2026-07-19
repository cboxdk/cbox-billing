<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Coupons\CouponAuthoring;
use App\Billing\Coupons\Enums\CouponDiscountKind;
use App\Billing\Coupons\Enums\CouponDuration;
use App\Billing\Coupons\Enums\CouponScope;
use App\Billing\Coupons\Exceptions\CouponActionDenied;
use App\Billing\Coupons\Exceptions\CouponAuthoringException;
use App\Billing\Coupons\ValueObjects\CouponDraft;
use App\Billing\Reporting\CouponReport;
use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Coupons console — thin HTTP over {@see CouponReport} (reads) and
 * {@see CouponAuthoring} (writes). Delete is guarded server-side: a coupon that has ever
 * been redeemed is archived, never hard-deleted, so its redemption ledger and any live
 * subscription discounts are never orphaned.
 */
class CouponController extends Controller
{
    public function index(Request $request, CouponReport $report): View
    {
        $search = $this->search($request);

        return view('billing.coupons', [
            'activeArea' => 'catalog',
            'activeNav' => 'coupons',
            'search' => $search,
            'coupons' => $report->paginate($search),
        ]);
    }

    public function show(Coupon $coupon, CouponReport $report): View
    {
        return view('billing.coupon-detail', [
            'activeArea' => 'catalog',
            'activeNav' => 'coupons',
            'coupon' => $report->find($coupon->id),
        ]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function edit(Coupon $coupon): View
    {
        return $this->form($coupon);
    }

    public function store(Request $request, CouponAuthoring $authoring): RedirectResponse
    {
        $draft = $this->validated($request);

        try {
            $coupon = $authoring->create($draft);
        } catch (CouponAuthoringException|CouponActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.coupons.show', $coupon->id)
            ->with('status', sprintf('Coupon “%s” created.', $coupon->code));
    }

    public function update(Request $request, Coupon $coupon, CouponAuthoring $authoring): RedirectResponse
    {
        $draft = $this->validated($request);

        try {
            $authoring->update($coupon, $draft);
        } catch (CouponAuthoringException|CouponActionDenied $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.coupons.show', $coupon->id)
            ->with('status', sprintf('Coupon “%s” updated.', $coupon->code));
    }

    public function archive(Coupon $coupon, CouponAuthoring $authoring): RedirectResponse
    {
        $authoring->archive($coupon);

        return back()->with('status', sprintf('Coupon “%s” archived.', $coupon->code));
    }

    public function unarchive(Coupon $coupon, CouponAuthoring $authoring): RedirectResponse
    {
        $authoring->unarchive($coupon);

        return back()->with('status', sprintf('Coupon “%s” reinstated.', $coupon->code));
    }

    public function destroy(Coupon $coupon, CouponAuthoring $authoring): RedirectResponse
    {
        $code = $coupon->code;

        try {
            $authoring->delete($coupon);
        } catch (CouponActionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('billing.coupons')
            ->with('status', sprintf('Coupon “%s” deleted.', $code));
    }

    private function form(?Coupon $coupon): View
    {
        return view('billing.coupon-form', [
            'activeArea' => 'catalog',
            'activeNav' => 'coupons',
            'coupon' => $coupon,
            'plans' => Plan::query()->orderBy('name')->get(['key', 'name']),
        ]);
    }

    private function validated(Request $request): CouponDraft
    {
        $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['nullable', 'string', 'max:160'],
            'discount_type' => ['required', 'in:percent,fixed_amount'],
            'percent_off' => ['nullable', 'required_if:discount_type,percent', 'integer', 'min:1', 'max:100'],
            'amount_off_minor' => ['nullable', 'required_if:discount_type,fixed_amount', 'integer', 'min:1'],
            'currency' => ['nullable', 'required_if:discount_type,fixed_amount', 'string', 'size:3'],
            'duration' => ['required', 'in:once,repeating,forever'],
            'duration_in_periods' => ['nullable', 'required_if:duration,repeating', 'integer', 'min:1'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_redemptions_per_customer' => ['nullable', 'integer', 'min:1'],
            'redeem_by' => ['nullable', 'date'],
            'applies_to' => ['required', 'in:all,plans'],
            'plans' => ['nullable', 'required_if:applies_to,plans', 'array'],
            'plans.*' => ['string'],
        ]);

        return new CouponDraft(
            code: $request->string('code')->toString(),
            name: $request->filled('name') ? $request->string('name')->toString() : null,
            kind: CouponDiscountKind::from($request->string('discount_type')->toString()),
            percentOff: $request->filled('percent_off') ? $request->integer('percent_off') : null,
            amountOffMinor: $request->filled('amount_off_minor') ? $request->integer('amount_off_minor') : null,
            currency: $request->filled('currency') ? strtoupper($request->string('currency')->toString()) : null,
            duration: CouponDuration::from($request->string('duration')->toString()),
            durationInPeriods: $request->filled('duration_in_periods') ? $request->integer('duration_in_periods') : null,
            maxRedemptions: $request->filled('max_redemptions') ? $request->integer('max_redemptions') : null,
            maxRedemptionsPerCustomer: $request->filled('max_redemptions_per_customer') ? $request->integer('max_redemptions_per_customer') : null,
            redeemBy: $request->filled('redeem_by') ? Carbon::parse($request->string('redeem_by')->toString()) : null,
            scope: CouponScope::from($request->string('applies_to')->toString()),
            planKeys: $this->planKeys($request),
            active: $request->boolean('active'),
        );
    }

    /**
     * @return list<string>
     */
    private function planKeys(Request $request): array
    {
        $plans = $request->input('plans', []);

        if (! is_array($plans)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($key): string => is_string($key) ? $key : '',
            $plans,
        ), static fn (string $key): bool => $key !== ''));
    }

    private function search(Request $request): ?string
    {
        $q = $request->query('q');

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }
}
