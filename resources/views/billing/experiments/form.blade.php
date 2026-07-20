@extends('layouts.app')
@section('title', $experiment !== null ? 'Edit experiment' : 'New experiment')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Experiments', 'href' => route('billing.experiments')],
        ['label' => $experiment !== null ? 'Edit' : 'New'],
    ]" />
@endsection

@php
    $editing = $experiment !== null;
    $action = $editing ? route('billing.experiments.update', $experiment->id) : route('billing.experiments.store');

    $existingVariants = $editing
        ? $experiment->variants->values()->map(fn ($v) => [
            'label' => $v->label,
            'weight' => $v->weight,
            'served_pricing_table_id' => $v->served_pricing_table_id,
            'is_control' => (bool) $v->is_control,
        ])->all()
        : [
            ['label' => 'Control', 'weight' => 1, 'served_pricing_table_id' => null, 'is_control' => true],
            ['label' => 'Variant B', 'weight' => 1, 'served_pricing_table_id' => null, 'is_control' => false],
        ];

    $oldVariants = old('variants');
    if (is_array($oldVariants)) {
        $oldControl = (string) old('control');
        $existingVariants = [];
        foreach ($oldVariants as $i => $row) {
            if (! is_array($row)) { continue; }
            $existingVariants[] = [
                'label' => $row['label'] ?? '',
                'weight' => $row['weight'] ?? 1,
                'served_pricing_table_id' => $row['served_pricing_table_id'] ?? null,
                'is_control' => (string) $i === $oldControl,
            ];
        }
    }

    $curKey = old('key', $editing ? $experiment->key : '');
    $curName = old('name', $editing ? $experiment->name : '');
    $curHypothesis = old('hypothesis', $editing ? $experiment->hypothesis : '');
    $curMetric = old('primary_metric', $editing ? $experiment->primary_metric->value : 'checkout_completed');
    $curTable = (string) old('pricing_table_id', $editing ? $experiment->pricing_table_id : '');

    $label = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $input = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit experiment' : 'New experiment' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">A controlled A/B test on a public pricing table — variants, traffic weights and the metric to optimise.</p>
        </div>
        <a href="{{ $editing ? route('billing.experiments.show', $experiment->id) : route('billing.experiments') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($editing)@method('PUT')@endif

        <section class="cbx-panel" style="padding:20px;display:flex;flex-direction:column;gap:16px">
            <h2 class="cbx-panel-title" style="font-size:14px">Basics</h2>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                <label style="{{ $label }}">Name
                    <input type="text" name="name" value="{{ $curName }}" maxlength="160" required style="{{ $input }}" placeholder="Annual pricing test">
                </label>
                <label style="{{ $label }}">Key
                    <input type="text" name="key" value="{{ $curKey }}" maxlength="120" required pattern="[a-z0-9._-]+" style="{{ $input }}" placeholder="annual-pricing-q3">
                </label>
                <label style="{{ $label }}">Runs on (pricing table)
                    <select name="pricing_table_id" required style="{{ $input }}">
                        <option value="">Choose a pricing table…</option>
                        @foreach ($options['tables'] as $table)
                            <option value="{{ $table['id'] }}" @selected($curTable === (string) $table['id'])>{{ $table['name'] }} · /pricing/{{ $table['key'] }}{{ $table['active'] ? '' : ' (offline)' }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $label }}">Primary metric
                    <select name="primary_metric" required style="{{ $input }}">
                        @foreach ($metrics as $metric)
                            <option value="{{ $metric->value }}" @selected($curMetric === $metric->value)>{{ $metric->label() }} — {{ $metric->description() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label style="{{ $label }}">Hypothesis <span class="mut" style="font-weight:400">(optional)</span>
                <textarea name="hypothesis" maxlength="2000" rows="2" style="{{ $input }};height:auto;padding:8px" placeholder="We believe an annual-first layout will lift checkout completion for growing teams.">{{ $curHypothesis }}</textarea>
            </label>
        </section>

        <section class="cbx-panel" style="padding:20px;display:flex;flex-direction:column;gap:12px;margin-top:14px">
            <div style="display:flex;align-items:center;justify-content:space-between">
                <div>
                    <h2 class="cbx-panel-title" style="font-size:14px">Variants</h2>
                    <p class="cbx-panel-desc" style="font-size:12px">One control is required. Traffic is split in proportion to the weights. A variant with no served table shows the base table (the control default).</p>
                </div>
                <button type="button" id="exp-add-variant" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'plus', 'size' => 13, 'sw' => 1.7])Add variant</button>
            </div>

            <div id="exp-variants" style="display:flex;flex-direction:column;gap:10px">
                @foreach ($existingVariants as $i => $variant)
                    @include('billing.experiments._variant-row', ['index' => $i, 'variant' => $variant, 'tables' => $options['tables'], 'input' => $input])
                @endforeach
            </div>
        </section>

        <div style="display:flex;gap:8px;margin-top:16px">
            <button type="submit" class="cbx-btn cbx-btn--primary">{{ $editing ? 'Save experiment' : 'Create experiment' }}</button>
            <a href="{{ $editing ? route('billing.experiments.show', $experiment->id) : route('billing.experiments') }}" class="cbx-btn">Cancel</a>
        </div>
    </form>
</div>

{{-- A blank variant-row template the "Add variant" button clones. --}}
<template id="exp-variant-template">
    @include('billing.experiments._variant-row', ['index' => '__INDEX__', 'variant' => ['label' => '', 'weight' => 1, 'served_pricing_table_id' => null, 'is_control' => false], 'tables' => $options['tables'], 'input' => $input])
</template>
@endsection

@section('scripts')
<script>
    (function () {
        var list = document.getElementById('exp-variants');
        var tpl = document.getElementById('exp-variant-template');
        var addBtn = document.getElementById('exp-add-variant');
        if (!list || !tpl || !addBtn) return;

        // A monotonically-increasing index so cloned rows never collide with server-rendered ones.
        var next = list.querySelectorAll('[data-variant-row]').length + 1000;

        function wireRemove(row) {
            var btn = row.querySelector('[data-remove-variant]');
            if (btn) btn.addEventListener('click', function () {
                if (list.querySelectorAll('[data-variant-row]').length <= 2) return; // keep control + 1
                row.remove();
            });
        }

        list.querySelectorAll('[data-variant-row]').forEach(wireRemove);

        addBtn.addEventListener('click', function () {
            var html = tpl.innerHTML.replace(/__INDEX__/g, String(next++));
            var frag = document.createElement('div');
            frag.innerHTML = html.trim();
            var row = frag.firstElementChild;
            list.appendChild(row);
            wireRemove(row);
        });
    })();
</script>
@endsection
