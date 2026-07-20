@extends('layouts.app')
@section('title', $quote ? 'Edit '.$quote->number : 'New quote')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Quotes', 'href' => route('billing.quotes')],
        ['label' => $quote ? $quote->number : 'New quote'],
    ]" />
@endsection

@php
    $action = $quote ? route('billing.quotes.update', $quote->id) : route('billing.quotes.store');
    // Prefill lines from old() input, else the model, else one empty row.
    $oldLines = old('lines');
    if (is_array($oldLines)) {
        $lineRows = array_values($oldLines);
    } elseif ($quote) {
        $lineRows = $quote->lines->map(fn ($l) => [
            'type' => $l->type->value,
            'plan_id' => $l->plan_id,
            'description' => $l->description,
            'quantity' => $l->quantity,
            'unit_amount' => $l->unit_amount_minor !== null ? number_format($l->unit_amount_minor / 100, 2, '.', '') : '',
            'discount_kind' => $l->discount_kind?->value,
            'discount_value' => $l->discount_kind?->value === 'fixed' && $l->discount_value !== null ? number_format($l->discount_value / 100, 2, '.', '') : $l->discount_value,
            'recurring' => $l->recurring ? '1' : '',
        ])->all();
    } else {
        $lineRows = [['type' => 'plan', 'quantity' => 1, 'recurring' => '1']];
    }

    $oldRamp = old('ramp');
    if (is_array($oldRamp)) {
        $rampRows = array_values($oldRamp);
    } elseif ($quote && is_array($quote->ramp)) {
        $rampRows = array_map(fn ($s) => ['from_period_index' => $s['from_period_index'], 'amount' => number_format($s['amount_minor'] / 100, 2, '.', '')], $quote->ramp);
    } else {
        $rampRows = [];
    }

    $val = fn ($key, $default = '') => old($key, $quote ? data_get($quote, $key, $default) : $default);
@endphp

@section('screen')
<div class="page" style="max-width:1000px">
    <x-back-button :href="$quote ? route('billing.quotes.show', $quote->id) : route('billing.quotes')" label="Back" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $quote ? 'Edit '.$quote->number : 'New quote' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Author the customer, line items, contract terms and commitment. Totals compute through the engine when you save.</p>
        </div>
    </header>

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($quote)@method('PUT')@endif

        <section class="cbx-panel" style="margin-bottom:16px">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Customer & header</h2></header>
            <div class="cbx-grid-2" style="padding:14px 20px;gap:14px 24px">
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Billing organization</span>
                    <select name="organization_id" class="cbx-input">
                        <option value="">— Prospect (no account yet) —</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}" @selected($val('organization_id') === $org->id)>{{ $org->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Currency</span>
                    <select name="currency" class="cbx-input" required>
                        @foreach ($currencies as $ccy)
                            <option value="{{ $ccy }}" @selected(strtoupper((string) $val('currency', $currencies[0] ?? 'DKK')) === $ccy)>{{ $ccy }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Prospect name (if no org)</span>
                    <input name="prospect_name" class="cbx-input" maxlength="200" value="{{ $val('prospect_name') }}">
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Prospect email</span>
                    <input name="prospect_email" type="email" class="cbx-input" maxlength="200" value="{{ $val('prospect_email') }}">
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Selling entity (branding)</span>
                    <select name="seller_entity_id" class="cbx-input">
                        <option value="">— Default —</option>
                        @foreach ($sellers as $seller)
                            <option value="{{ $seller->id }}" @selected($val('seller_entity_id') === $seller->id)>{{ $seller->legal_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Valid until</span>
                    <input name="valid_until" type="date" class="cbx-input" value="{{ old('valid_until', $quote?->valid_until?->format('Y-m-d')) }}">
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Order coupon</span>
                    <select name="coupon_id" class="cbx-input">
                        <option value="">— None —</option>
                        @foreach ($coupons as $coupon)
                            <option value="{{ $coupon->id }}" @selected((string) $val('coupon_id') === (string) $coupon->id)>{{ $coupon->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="display:block">
                    <span class="mut" style="font-size:12px">Owner (rep)</span>
                    <input name="owner_name" class="cbx-input" maxlength="200" value="{{ $val('owner_name') }}">
                </label>
                <label style="display:block;grid-column:1/-1">
                    <span class="mut" style="font-size:12px">Notes</span>
                    <textarea name="notes" class="cbx-input" rows="2" maxlength="5000">{{ $val('notes') }}</textarea>
                </label>
            </div>
        </section>

        <section class="cbx-panel" style="margin-bottom:16px">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;justify-content:space-between;align-items:center"><h2 class="cbx-panel-title" style="font-size:14px">Line items</h2><button type="button" class="cbx-btn cbx-btn--sm" id="add-line">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7]) Add line</button></header>
            <div style="padding:8px 12px">
                <table class="tbl" id="lines-table">
                    <thead><tr><th style="width:100px">Type</th><th>Plan / description</th><th style="width:80px">Qty</th><th style="width:120px">Unit (custom)</th><th style="width:150px">Discount</th><th style="width:36px"></th></tr></thead>
                    <tbody id="lines-body">
                        @foreach ($lineRows as $i => $row)
                            @include('billing.quotes._line-row', ['i' => $i, 'row' => $row, 'plans' => $plans])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="cbx-panel" style="margin-bottom:16px">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Contract terms</h2></header>
            <div class="cbx-grid-3" style="padding:14px 20px;gap:14px 24px">
                <label style="display:block"><span class="mut" style="font-size:12px">Term length</span><input name="term_count" type="number" min="1" max="120" class="cbx-input" value="{{ $val('term_count', 12) }}" required></label>
                <label style="display:block"><span class="mut" style="font-size:12px">Term unit</span>
                    <select name="term_unit" class="cbx-input">
                        @foreach (['month' => 'Months', 'year' => 'Years', 'day' => 'Days'] as $k => $lbl)<option value="{{ $k }}" @selected($val('term_unit', 'month') === $k)>{{ $lbl }}</option>@endforeach
                    </select>
                </label>
                <label style="display:block"><span class="mut" style="font-size:12px">Billing interval</span>
                    <select name="billing_interval" class="cbx-input">
                        @foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly'] as $k => $lbl)<option value="{{ $k }}" @selected($val('billing_interval', 'monthly') === $k)>{{ $lbl }}</option>@endforeach
                    </select>
                </label>
                <label style="display:block"><span class="mut" style="font-size:12px">Start date</span><input name="start_date" type="date" class="cbx-input" value="{{ old('start_date', $quote?->start_date?->format('Y-m-d')) }}"></label>
                <label style="display:block"><span class="mut" style="font-size:12px">Min. commitment / period</span><input name="minimum_commitment" type="number" step="0.01" min="0" class="cbx-input" value="{{ old('minimum_commitment', $quote?->minimum_commitment_minor !== null ? number_format($quote->minimum_commitment_minor / 100, 2, '.', '') : '') }}" placeholder="e.g. 5000.00"></label>
            </div>
            <div style="padding:0 20px 16px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><span class="mut" style="font-size:12px">Ramp schedule (optional — a step from period 0 is required if any)</span><button type="button" class="cbx-btn cbx-btn--sm" id="add-ramp">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7]) Add step</button></div>
                <table class="tbl" id="ramp-table" style="{{ $rampRows === [] ? 'display:none' : '' }}">
                    <thead><tr><th style="width:180px">From period (0-based)</th><th>Amount / period</th><th style="width:36px"></th></tr></thead>
                    <tbody id="ramp-body">
                        @foreach ($rampRows as $i => $row)
                            @include('billing.quotes._ramp-row', ['i' => $i, 'row' => $row])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div style="display:flex;gap:8px;justify-content:flex-end">
            <a href="{{ $quote ? route('billing.quotes.show', $quote->id) : route('billing.quotes') }}" class="cbx-btn cbx-btn--sm">Cancel</a>
            <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">{{ $quote ? 'Save quote' : 'Create quote' }}</button>
        </div>
    </form>
</div>

<template id="line-template">
    @include('billing.quotes._line-row', ['i' => '__IDX__', 'row' => ['type' => 'plan', 'quantity' => 1, 'recurring' => '1'], 'plans' => $plans])
</template>
<template id="ramp-template">
    @include('billing.quotes._ramp-row', ['i' => '__IDX__', 'row' => []])
</template>

<script>
(function () {
    var lineIdx = {{ count($lineRows) }};
    var rampIdx = {{ count($rampRows) }};
    function mount(tplId, bodyId, counterGet, counterSet) {
        var tpl = document.getElementById(tplId).innerHTML;
        var html = tpl.replace(/__IDX__/g, counterGet());
        var tmp = document.createElement('tbody');
        tmp.innerHTML = html;
        document.getElementById(bodyId).appendChild(tmp.firstElementChild);
        counterSet(counterGet() + 1);
    }
    document.getElementById('add-line').addEventListener('click', function () {
        mount('line-template', 'lines-body', function(){return lineIdx;}, function(v){lineIdx=v;});
    });
    document.getElementById('add-ramp').addEventListener('click', function () {
        document.getElementById('ramp-table').style.display = '';
        mount('ramp-template', 'ramp-body', function(){return rampIdx;}, function(v){rampIdx=v;});
    });
    document.addEventListener('click', function (e) {
        var rm = e.target.closest('[data-remove-row]');
        if (!rm) return;
        var row = rm.closest('tr');
        if (row) row.remove();
    });
})();
</script>
@endsection
