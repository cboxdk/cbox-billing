@extends('layouts.app')
@section('title', $meter !== null ? 'Edit meter' : 'New meter')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Meters', 'href' => route('billing.meters')],
        ['label' => $meter !== null ? 'Edit meter' : 'New meter'],
    ]" />
@endsection

@php
    $editing = $meter !== null;
    $action = $editing ? route('billing.meters.update', $meter->id) : route('billing.meters.store');
    $curKey = old('key', $editing ? $meter->key : '');
    $curName = old('name', $editing ? $meter->name : '');
    $curUnit = old('unit', $editing ? $meter->unit : '');
    $curAgg = old('aggregation', $editing ? $meter->aggregation->value : 'sum');
    $curDisplay = old('display', $editing ? $meter->display : '');
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px';
    $aggHelp = [
        'count' => 'The number of events (each event is one unit; value ignored).',
        'sum' => 'The sum of every event’s value — the classic usage total.',
        'max' => 'The largest single value in the window (e.g. peak seats).',
        'unique_count' => 'The number of distinct unique keys (e.g. unique active users).',
        'latest' => 'The value of the most recent event — a gauge’s last reading.',
        'weighted_sum' => 'The sum of value × weight — a cost-weighted total.',
    ];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit meter' : 'New meter' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">A metered dimension. Its aggregation is how the engine collapses raw usage events into one billable quantity per period.</p>
        </div>
        <a href="{{ $editing ? route('billing.meters.show', $meter->id) : route('billing.meters') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Key
                    <input type="text" name="key" value="{{ $curKey }}" required maxlength="120" placeholder="api.requests" pattern="[a-z0-9._-]+" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">The handle carried on each usage event — lowercase, digits, dot, dash, underscore.</span>
                </label>
                <label style="{{ $labelStyle }}">Name
                    <input type="text" name="name" value="{{ $curName }}" required maxlength="160" placeholder="API requests" style="{{ $inputStyle }}">
                </label>
            </div>

            <div class="cbx-grid-3" style="align-items:start">
                <label style="{{ $labelStyle }}">Unit
                    <input type="text" name="unit" value="{{ $curUnit }}" required maxlength="60" placeholder="requests" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Aggregation
                    <select name="aggregation" id="agg" required style="{{ $inputStyle }}" onchange="syncAgg()">
                        @foreach ($aggregations as $aggregation)
                            <option value="{{ $aggregation->value }}" @selected($curAgg === $aggregation->value)>{{ $aggregation->value }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="{{ $labelStyle }}">Display label <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="text" name="display" value="{{ $curDisplay }}" maxlength="160" placeholder="Falls back to name" style="{{ $inputStyle }}">
                </label>
            </div>
            <p class="mut" id="agg-help" style="font-size:12px;margin:-6px 0 0"></p>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create meter' }}</button>
                <a href="{{ $editing ? route('billing.meters.show', $meter->id) : route('billing.meters') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    const AGG_HELP = @json($aggHelp);
    function syncAgg() {
        document.getElementById('agg-help').textContent = AGG_HELP[document.getElementById('agg').value] || '';
    }
    syncAgg();
</script>
@endsection
