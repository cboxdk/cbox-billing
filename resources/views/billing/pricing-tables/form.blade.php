@extends('layouts.app')
@section('title', $table !== null ? 'Edit pricing table' : 'New pricing table')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Pricing tables', 'href' => route('billing.pricing-tables')],
        ['label' => $table !== null ? 'Edit' : 'New'],
    ]" />
@endsection

@php
    $editing = $table !== null;
    $action = $editing ? route('billing.pricing-tables.update', $table->id) : route('billing.pricing-tables.store');

    $existingColumns = $editing
        ? $table->columns->map(fn ($c) => [
            'plan_id' => $c->plan_id,
            'annual_plan_id' => $c->annual_plan_id,
            'featured' => (bool) $c->featured,
            'badge' => $c->badge,
            'highlight' => $c->highlight,
        ])->all()
        : [];
    $columns = old('columns', $existingColumns);
    // Normalise old() rows (they arrive as arrays of strings) to a predictable shape.
    $columns = array_values(array_filter($columns, fn ($c) => is_array($c)));

    $existingFeatures = $editing ? $table->featureRows->pluck('feature_id')->map(fn ($id) => (string) $id)->all() : [];
    $selectedFeatures = array_map('strval', old('features', $existingFeatures));

    $selectedCurrencies = array_map('strtoupper', old('currencies', $editing ? ($table->currencies ?? []) : []));
    $curKey = old('key', $editing ? $table->key : '');
    $curName = old('name', $editing ? $table->name : '');
    $curSeller = old('seller_entity_id', $editing ? $table->seller_entity_id : '');
    $curDefaultCurrency = old('default_currency', $editing ? $table->default_currency : '');
    $curCtaLabel = old('cta_label', $editing ? $table->cta_label : '');
    $curCtaUrl = old('cta_url_template', $editing ? $table->cta_url_template : '');
    $curIntervalToggle = (bool) old('interval_toggle', $editing ? $table->interval_toggle : true);
    $curActive = (bool) old('active', $editing ? $table->active : true);

    $label = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $input = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit pricing table' : 'New pricing table' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">An embeddable, public pricing surface projected from your live catalog.</p>
        </div>
        <a href="{{ $editing ? route('billing.pricing-tables.show', $table->id) : route('billing.pricing-tables') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($editing)@method('PUT')@endif

        {{-- Basics --}}
        <section class="cbx-panel" style="margin-bottom:16px">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Basics</h2></header>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:16px">
                <div class="cbx-grid-2" style="align-items:start">
                    <label style="{{ $label }}">Public slug
                        <input type="text" name="key" value="{{ $curKey }}" required maxlength="120" pattern="[a-z0-9._-]+" placeholder="pricing" style="{{ $input }}">
                        <span class="mut" style="font-size:11px">Addresses /pricing/&lt;slug&gt; — lowercase letters, digits, dot, dash, underscore.</span>
                    </label>
                    <label style="{{ $label }}">Name
                        <input type="text" name="name" value="{{ $curName }}" required maxlength="160" placeholder="Plans &amp; pricing" style="{{ $input }}">
                    </label>
                </div>
                <div class="cbx-grid-2" style="align-items:start">
                    <label style="{{ $label }}">Branding (selling entity)
                        <select name="seller_entity_id" style="{{ $input }}">
                            <option value="">Default / app-level branding</option>
                            @foreach ($options['sellers'] as $seller)
                                <option value="{{ $seller['id'] }}" @selected($curSeller === $seller['id'])>{{ $seller['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label style="{{ $label }}">Default currency
                        <select name="default_currency" style="{{ $input }}">
                            <option value="">First presented</option>
                            @foreach ($options['currencies'] as $c)
                                <option value="{{ $c }}" @selected($curDefaultCurrency === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div style="{{ $label }}">Currencies to present
                    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:2px">
                        @forelse ($options['currencies'] as $c)
                            <label style="display:inline-flex;align-items:center;gap:6px;font-weight:400;font-size:13px">
                                <input type="checkbox" name="currencies[]" value="{{ $c }}" @checked(in_array($c, $selectedCurrencies, true))> {{ $c }}
                            </label>
                        @empty
                            <span class="mut" style="font-size:12px">No priced currencies in the catalog yet.</span>
                        @endforelse
                    </div>
                    <span class="mut" style="font-size:11px">Leave all unchecked to present every currency your plans are priced in.</span>
                </div>
                <div class="cbx-grid-2" style="align-items:start">
                    <label style="{{ $label }}">CTA label
                        <input type="text" name="cta_label" value="{{ $curCtaLabel }}" maxlength="80" placeholder="Get started" style="{{ $input }}">
                    </label>
                    <label style="{{ $label }}">CTA target URL
                        <input type="text" name="cta_url_template" value="{{ $curCtaUrl }}" maxlength="2048" placeholder="https://app.example.com/signup?plan={plan}&currency={currency}&interval={interval}" style="{{ $input }}">
                        <span class="mut" style="font-size:11px">Where the button links. Use <code class="num">{plan}</code> <code class="num">{currency}</code> <code class="num">{interval}</code> <code class="num">{price}</code>; unset uses the configured checkout URL.</span>
                    </label>
                </div>
                <div style="display:flex;gap:22px;flex-wrap:wrap">
                    <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                        <input type="checkbox" name="interval_toggle" value="1" @checked($curIntervalToggle)> Show monthly / yearly toggle
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                        <input type="checkbox" name="active" value="1" @checked($curActive)> Live (publicly reachable)
                    </label>
                </div>
            </div>
        </section>

        {{-- Plan columns --}}
        <section class="cbx-panel" style="margin-bottom:16px">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
                <h2 class="cbx-panel-title" style="font-size:14px">Plan columns</h2>
                <button type="button" class="cbx-btn cbx-btn--sm" id="pt-add-col">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])Add column</button>
            </header>
            <div style="padding:14px 20px">
                <p class="mut" style="font-size:11px;margin:0 0 10px">Columns render left-to-right in this order. Set an annual plan to make the yearly toggle switch this column's price. Mark one column featured to lift it.</p>
                <div id="pt-columns" style="display:flex;flex-direction:column;gap:10px">
                    @foreach ($columns as $i => $col)
                        @include('billing.pricing-tables._column-row', ['i' => $i, 'col' => $col, 'options' => $options, 'input' => $input])
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Feature comparison --}}
        <section class="cbx-panel" style="margin-bottom:16px">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Feature comparison</h2></header>
            <div style="padding:14px 20px">
                <p class="mut" style="font-size:11px;margin:0 0 10px">The rows of the ✓/✗/value matrix, compared across the columns. Cells are read from each plan's feature grants.</p>
                <div style="display:flex;flex-direction:column;gap:8px">
                    @forelse ($options['features'] as $feature)
                        <label style="display:flex;align-items:baseline;gap:10px;font-size:13px">
                            <input type="checkbox" name="features[]" value="{{ $feature['id'] }}" @checked(in_array((string) $feature['id'], $selectedFeatures, true))>
                            <span><span style="font-weight:600">{{ $feature['name'] }}</span> <code class="num mut" style="font-size:11px">{{ $feature['key'] }}</code> <span class="cbx-pill cbx-pill--muted">{{ $feature['type'] }}</span></span>
                        </label>
                    @empty
                        <span class="mut" style="font-size:12px">No features in the catalog yet.</span>
                    @endforelse
                </div>
            </div>
        </section>

        <div style="display:flex;gap:10px">
            <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create pricing table' }}</button>
            <a href="{{ $editing ? route('billing.pricing-tables.show', $table->id) : route('billing.pricing-tables') }}" class="cbx-btn">Cancel</a>
        </div>
    </form>
</div>

{{-- A blank column-row template the "Add column" button clones. --}}
<template id="pt-col-template">
    @include('billing.pricing-tables._column-row', ['i' => '__INDEX__', 'col' => [], 'options' => $options, 'input' => $input])
</template>
@endsection

@section('scripts')
<script>
(function () {
    var wrap = document.getElementById('pt-columns');
    var tpl = document.getElementById('pt-col-template');
    var addBtn = document.getElementById('pt-add-col');
    if (!wrap || !tpl || !addBtn) return;

    var counter = wrap.querySelectorAll('[data-col-row]').length;

    function bindRemove(row) {
        var btn = row.querySelector('[data-col-remove]');
        if (btn) btn.addEventListener('click', function () { row.remove(); });
    }

    wrap.querySelectorAll('[data-col-row]').forEach(bindRemove);

    addBtn.addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__INDEX__/g, String(counter++));
        var frag = document.createElement('div');
        frag.innerHTML = html.trim();
        var row = frag.firstElementChild;
        if (!row) return;
        wrap.appendChild(row);
        bindRemove(row);
    });
})();
</script>
@endsection
