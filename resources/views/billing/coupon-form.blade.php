@extends('layouts.app')
@section('title', $coupon !== null ? 'Edit coupon' : 'New coupon')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Coupons', 'href' => route('billing.coupons')],
        ['label' => $coupon !== null ? 'Edit coupon' : 'New coupon'],
    ]" />
@endsection

@php
    $editing = $coupon !== null;
    $action = $editing ? route('billing.coupons.update', $coupon->id) : route('billing.coupons.store');
    $curCode = old('code', $editing ? $coupon->code : '');
    $curName = old('name', $editing ? $coupon->name : '');
    $curType = old('discount_type', $editing ? $coupon->discount_type : 'percent');
    $curPercent = old('percent_off', $editing ? $coupon->percent_off : 20);
    $curAmount = old('amount_off_minor', $editing ? $coupon->amount_off_minor : '');
    $curCurrency = old('currency', $editing ? $coupon->currency : '');
    $curDuration = old('duration', $editing ? $coupon->duration : 'once');
    $curPeriods = old('duration_in_periods', $editing ? $coupon->duration_in_periods : '');
    $curMax = old('max_redemptions', $editing ? $coupon->max_redemptions : '');
    $curMaxCust = old('max_redemptions_per_customer', $editing ? $coupon->max_redemptions_per_customer : '');
    $curRedeemBy = old('redeem_by', $editing && $coupon->redeem_by ? $coupon->redeem_by->format('Y-m-d') : '');
    $curScope = old('applies_to', $editing ? $coupon->applies_to : 'all');
    $curPlans = old('plans', $editing ? ($coupon->applies_to_plans ?? []) : []);
    $curActive = old('active', $editing ? ($coupon->active ? '1' : '0') : '1');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit coupon' : 'New coupon' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">A coupon reduces the net (pre-tax) amount via the billing engine. The discount, its duration and its limits are set here.</p>
        </div>
        <a href="{{ $editing ? route('billing.coupons.show', $coupon->id) : route('billing.coupons') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Code
                    <input type="text" name="code" value="{{ $curCode }}" required maxlength="60" placeholder="SAVE20" pattern="[A-Za-z0-9._-]+" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">Case-insensitive. Letters, digits, dot, dash, underscore.</span>
                </label>
                <label style="{{ $labelStyle }}">Name <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="text" name="name" value="{{ $curName }}" maxlength="160" placeholder="Launch promo" style="{{ $inputStyle }}">
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Discount type
                    <select name="discount_type" style="{{ $inputStyle }}">
                        <option value="percent" @selected($curType === 'percent')>Percentage off</option>
                        <option value="fixed_amount" @selected($curType === 'fixed_amount')>Fixed amount off</option>
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Percentage off <span class="mut" style="font-weight:400">(percentage type)</span>
                    <input type="number" name="percent_off" value="{{ $curPercent }}" min="1" max="100" placeholder="20" style="{{ $inputStyle }}">
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Amount off (minor units) <span class="mut" style="font-weight:400">(fixed type)</span>
                    <input type="number" name="amount_off_minor" value="{{ $curAmount }}" min="1" placeholder="2500" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">e.g. 2500 = 25.00 in the coupon's currency.</span>
                </label>
                <label style="{{ $labelStyle }}">Currency <span class="mut" style="font-weight:400">(fixed type)</span>
                    <input type="text" name="currency" value="{{ $curCurrency }}" maxlength="3" placeholder="DKK" pattern="[A-Za-z]{3}" style="{{ $inputStyle }};text-transform:uppercase">
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Duration
                    <select name="duration" style="{{ $inputStyle }}">
                        <option value="once" @selected($curDuration === 'once')>Once — the first invoice only</option>
                        <option value="repeating" @selected($curDuration === 'repeating')>Repeating — the next N invoices</option>
                        <option value="forever" @selected($curDuration === 'forever')>Forever — every renewal</option>
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Repeating periods <span class="mut" style="font-weight:400">(repeating only)</span>
                    <input type="number" name="duration_in_periods" value="{{ $curPeriods }}" min="1" placeholder="3" style="{{ $inputStyle }}">
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Max redemptions <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="number" name="max_redemptions" value="{{ $curMax }}" min="1" placeholder="Unlimited" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Per-customer limit <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="number" name="max_redemptions_per_customer" value="{{ $curMaxCust }}" min="1" placeholder="Unlimited" style="{{ $inputStyle }}">
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Expires <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="date" name="redeem_by" value="{{ $curRedeemBy }}" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Applies to
                    <select name="applies_to" style="{{ $inputStyle }}">
                        <option value="all" @selected($curScope === 'all')>All plans</option>
                        <option value="plans" @selected($curScope === 'plans')>Specific plans</option>
                    </select>
                </label>
            </div>

            <fieldset style="border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin:0">
                <legend class="mut" style="font-size:12px;padding:0 6px">Plans (when scoped to specific plans)</legend>
                <div style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow:auto">
                    @forelse ($plans as $plan)
                        <label style="display:flex;gap:8px;align-items:center;font-size:13px;font-weight:400">
                            <input type="checkbox" name="plans[]" value="{{ $plan->key }}" @checked(in_array($plan->key, (array) $curPlans, true))>
                            <span>{{ $plan->name }} <span class="num mut" style="font-size:11px">{{ $plan->key }}</span></span>
                        </label>
                    @empty
                        <span class="mut" style="font-size:12px">No plans exist yet.</span>
                    @endforelse
                </div>
            </fieldset>

            <label style="display:flex;gap:8px;align-items:center;font-size:13px;font-weight:500">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" @checked($curActive === '1' || $curActive === 1 || $curActive === true)>
                Active — redeemable now
            </label>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create coupon' }}</button>
                <a href="{{ $editing ? route('billing.coupons.show', $coupon->id) : route('billing.coupons') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
