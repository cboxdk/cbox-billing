@extends('layouts.app')
@section('title', 'New subscription')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Subscriptions', 'href' => route('billing.subscriptions')],
        ['label' => 'New subscription'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    // The currencies each plan is priced in, so the currency picker can react to the plan.
    $planCurrencies = [];
    foreach ($plans as $plan) {
        $planCurrencies[$plan->key] = $plan->prices->pluck('currency')->unique()->values()->all();
    }
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">New subscription</h1>
            <p class="cbx-page-desc" style="font-size:13px">Subscribe an organization to a plan. The first finalized invoice locks the account's billing currency, so pick it with care.</p>
        </div>
        <a href="{{ route('billing.subscriptions') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ route('billing.subscriptions.store') }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px;max-width:560px">
            @csrf

            <label style="{{ $labelStyle }}">Customer
                <select name="organization_id" required style="{{ $inputStyle }}">
                    <option value="">Select an organization…</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org->id }}" @selected(old('organization_id', $selectedOrg) === $org->id) data-currency="{{ $org->billing_currency }}">{{ $org->name }}@if($org->billing_currency) · locked to {{ $org->billing_currency }}@endif</option>
                    @endforeach
                </select>
            </label>

            <label style="{{ $labelStyle }}">Plan
                <select name="plan" id="plan-select" required style="{{ $inputStyle }}">
                    <option value="">Select a plan…</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->key }}" @selected(old('plan') === $plan->key)>{{ $plan->name }}</option>
                    @endforeach
                </select>
            </label>

            <div class="cbx-grid-2" style="gap:12px">
                <label style="{{ $labelStyle }}">Currency
                    <select name="currency" id="currency-select" required style="{{ $inputStyle }}"></select></label>
                <label style="{{ $labelStyle }}">Seats / quantity
                    <input type="number" name="seats" min="1" value="{{ old('seats', 1) }}" required class="num" style="{{ $inputStyle }}"></label>
            </div>

            <div class="cbx-grid-2" style="gap:12px;align-items:end">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px">
                    <input type="checkbox" name="trial" value="1" id="trial-check" @checked(old('trial'))> Start in a free trial
                </label>
                <label style="{{ $labelStyle }}">Trial days (optional)
                    <input type="number" name="trial_days" min="1" max="365" value="{{ old('trial_days') }}" placeholder="14" class="num" style="{{ $inputStyle }}"></label>
            </div>

            <label style="{{ $labelStyle }}">Promo code (optional)
                <input type="text" name="coupon" value="{{ old('coupon') }}" maxlength="60" placeholder="SAVE20" class="num" style="{{ $inputStyle }}">
                <span class="mut" style="font-size:11px">A coupon reduces the recurring net; unknown/expired/inapplicable codes are refused.</span></label>

            <div style="display:flex;gap:8px">
                <button type="submit" class="cbx-btn cbx-btn--primary">Create subscription</button>
                <a href="{{ route('billing.subscriptions') }}" class="cbx-btn cbx-btn--sm">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    (function(){
        var planCurrencies = @json($planCurrencies);
        var orgSelect = document.querySelector('select[name="organization_id"]');
        var planSelect = document.getElementById('plan-select');
        var currencySelect = document.getElementById('currency-select');
        var old = @json(old('currency'));
        function refresh(){
            var currencies = planCurrencies[planSelect.value] || [];
            var lockedOpt = orgSelect.options[orgSelect.selectedIndex];
            var locked = lockedOpt ? lockedOpt.getAttribute('data-currency') : '';
            currencySelect.innerHTML = '';
            currencies.forEach(function(c){
                var o = document.createElement('option');
                o.value = c; o.textContent = c;
                if ((locked && c === locked) || (!locked && old === c)) o.selected = true;
                currencySelect.appendChild(o);
            });
            if (!currencies.length){
                var o = document.createElement('option'); o.value=''; o.textContent='— pick a plan first —'; currencySelect.appendChild(o);
            }
        }
        planSelect.addEventListener('change', refresh);
        orgSelect.addEventListener('change', refresh);
        refresh();
    })();
</script>
@endsection
