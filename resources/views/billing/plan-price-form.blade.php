@extends('layouts.app')
@section('title', 'Plan price')
@section('crumb', 'Catalog')

@php
    use App\Billing\Support\MoneyFormatter;

    $editing = $price !== null;
    $action = $editing
        ? route('billing.catalog.prices.update', $price->id)
        : route('billing.catalog.prices.store');

    $curModel = old('pricing_model', $editing ? $price->pricing_model : 'flat');
    $curPlan = (string) old('plan_id', $editing ? $price->plan_id : '');
    $curCurrency = old('currency', $editing ? $price->currency : '');
    $curBase = old('price_minor', $editing ? $price->price_minor : '');
    $curPackage = old('package_size', $editing ? $price->package_size : '');

    // Tier rows to pre-render: submitted-back input on a validation error, else the
    // existing tier set when editing, else empty (the editor seeds a template pair).
    $curTiers = old('tiers', $editing
        ? $price->tiers->map(fn ($t) => ['up_to' => $t->up_to, 'unit_minor' => $t->unit_minor, 'flat_minor' => $t->flat_minor])->values()->all()
        : []);

    // Descriptions shown under the model select so the operator knows what the tiers mean.
    $modelHelp = [
        'flat' => 'A fixed amount regardless of quantity.',
        'per_unit' => 'The base amount charged per seat (base × seats).',
        'graduated' => 'Each seat slice is priced at its own tier’s unit rate; the charge is the sum across tiers.',
        'volume' => 'Every seat is priced at the single tier the total lands in.',
        'package' => 'A flat block price per package of N seats (ceil(seats ÷ size) blocks).',
        'stairstep' => 'One flat amount for the whole bracket the seat count lands in.',
    ];

    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit price' : 'New price' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Choose a pricing model and, for the tiered models, author the tier table the engine prices from.</p>
        </div>
        <a href="{{ route('billing.catalog') }}" class="cbx-btn">Back to catalog</a>
    </header>

    @if (session('catalog_error'))
        <div class="cbx-panel" style="padding:12px 20px;margin-bottom:14px;border-left:3px solid var(--destructive)">
            <strong style="color:var(--destructive)">Could not save the price.</strong> <span class="mut">{{ session('catalog_error') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="cbx-panel" style="padding:12px 20px;margin-bottom:14px;border-left:3px solid var(--destructive)">
            <strong style="color:var(--destructive)">Check the form.</strong>
            <ul class="mut" style="margin:6px 0 0;padding-left:18px;font-size:12px">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">{{ $editing ? $price->plan?->name.' — '.$price->currency : 'Price details' }}</h2></header>

        <form method="POST" action="{{ $action }}" id="price-form" style="padding:8px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:end">
                <label style="{{ $labelStyle }}">Plan
                    @if ($editing)
                        <input type="text" value="{{ $price->plan?->name }} ({{ $price->plan?->key }})" disabled style="{{ $inputStyle }};opacity:.7">
                        <input type="hidden" name="plan_id" value="{{ $price->plan_id }}">
                    @else
                        <select name="plan_id" required style="{{ $inputStyle }}">
                            <option value="">Select a plan…</option>
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->id }}" @selected($curPlan === (string) $plan->id)>{{ $plan->product?->name }} · {{ $plan->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </label>

                <label style="{{ $labelStyle }}">Currency
                    @if ($editing)
                        <input type="text" value="{{ $price->currency }}" disabled style="{{ $inputStyle }};opacity:.7">
                        <input type="hidden" name="currency" value="{{ $price->currency }}">
                    @else
                        <input type="text" name="currency" value="{{ $curCurrency }}" required maxlength="3" placeholder="DKK" style="{{ $inputStyle }};text-transform:uppercase">
                    @endif
                </label>

                <label style="{{ $labelStyle }}">Pricing model
                    <select name="pricing_model" id="pricing-model" required style="{{ $inputStyle }}" onchange="syncModel()">
                        @foreach ($models as $model)
                            <option value="{{ $model->value }}" @selected($curModel === $model->value)>{{ $model->value }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <p class="mut" id="model-help" style="font-size:12px;margin:-6px 0 0"></p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:end">
                <label style="{{ $labelStyle }}"><span id="base-label">Base amount (minor units)</span>
                    <input type="number" name="price_minor" value="{{ $curBase }}" required min="0" step="1" placeholder="124000" style="{{ $inputStyle }}">
                </label>

                <label style="{{ $labelStyle }}" id="package-size-field">Package size (seats per block)
                    <input type="number" name="package_size" value="{{ $curPackage }}" min="1" step="1" placeholder="10" style="{{ $inputStyle }}">
                </label>
            </div>

            {{-- Tier editor (tiered models only). Rows are up-to / unit / flat; the final row
                 leaves "up to" empty (unbounded). --}}
            <div id="tier-editor" style="display:none;flex-direction:column;gap:8px">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:12px;font-weight:600">Tiers</span>
                    <button type="button" class="cbx-btn" onclick="addTier()">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])Add tier</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 32px;gap:8px;font-size:11px;color:var(--secondary,#888)">
                    <span>Up to (seats, empty = ∞)</span><span>Unit (minor)</span><span>Flat (minor)</span><span></span>
                </div>
                <div id="tier-rows" style="display:flex;flex-direction:column;gap:8px"></div>
                <p class="mut" style="font-size:11px;margin:0">Bounds must ascend; the last tier must be unbounded (empty “up to”). For package pricing the block price is the first tier’s flat amount.</p>
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create price' }}</button>
                <a href="{{ route('billing.catalog') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    const MODEL_HELP = @json($modelHelp);
    const TIERED = ['graduated', 'volume', 'package', 'stairstep'];
    const existingTiers = @json(array_values($curTiers));
    let tierIndex = 0;

    function tierRow(upTo, unit, flat) {
        const i = tierIndex++;
        const cell = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px';
        const row = document.createElement('div');
        row.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr 32px;gap:8px';
        row.innerHTML =
            '<input type="number" name="tiers[' + i + '][up_to]" min="1" step="1" placeholder="∞" style="' + cell + '">' +
            '<input type="number" name="tiers[' + i + '][unit_minor]" min="0" step="1" placeholder="0" style="' + cell + '">' +
            '<input type="number" name="tiers[' + i + '][flat_minor]" min="0" step="1" placeholder="—" style="' + cell + '">' +
            '<button type="button" class="cbx-btn" title="Remove" style="padding:0" onclick="this.parentNode.remove()">✕</button>';
        const inputs = row.querySelectorAll('input');
        if (upTo !== null && upTo !== undefined && upTo !== '') inputs[0].value = upTo;
        if (unit !== null && unit !== undefined && unit !== '') inputs[1].value = unit;
        if (flat !== null && flat !== undefined && flat !== '') inputs[2].value = flat;
        return row;
    }

    function addTier(upTo, unit, flat) {
        document.getElementById('tier-rows').appendChild(tierRow(upTo, unit, flat));
    }

    function syncModel() {
        const model = document.getElementById('pricing-model').value;
        const tiered = TIERED.includes(model);
        document.getElementById('tier-editor').style.display = tiered ? 'flex' : 'none';
        document.getElementById('package-size-field').style.display = model === 'package' ? 'flex' : 'none';
        document.getElementById('base-label').textContent = model === 'per_unit'
            ? 'Unit amount per seat (minor units)'
            : 'Base amount (minor units)';
        document.getElementById('model-help').textContent = MODEL_HELP[model] || '';

        // Seed a starter pair the first time a tiered model is chosen with no rows yet.
        if (tiered && document.getElementById('tier-rows').children.length === 0) {
            addTier('', '', '');
            addTier('', '', '');
        }
    }

    // Prefill existing/submitted-back tiers, then reflect the current model.
    if (existingTiers.length) {
        existingTiers.forEach(t => addTier(t.up_to, t.unit_minor, t.flat_minor));
    }
    syncModel();
</script>
@endsection
