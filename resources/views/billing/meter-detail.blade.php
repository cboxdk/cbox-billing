@extends('layouts.app')
@section('title', $meter['name'] ?? 'Meter')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Meters', 'href' => route('billing.meters')],
        ['label' => $meter['name'] ?? 'Meter'],
    ]" />
@endsection

@php($m = $meter)

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.meters') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to meters</a>

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $m['name'] }}
                @if ($m['archived'])<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">archived</span>@endif
            </h1>
            <p class="cbx-page-desc num" style="font-size:13px">{{ $m['key'] }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="{{ route('billing.meters.edit', $m['id']) }}" class="cbx-btn cbx-btn--sm">Edit</a>
            @if ($m['archived'])
                <form method="POST" action="{{ route('billing.meters.unarchive', $m['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Reinstate</button></form>
            @else
                <form method="POST" action="{{ route('billing.meters.archive', $m['id']) }}" style="margin:0"
                      data-confirm="Archive {{ $m['name'] }}? Existing entitlements keep resolving its policy; it is hidden from new-entitlement pickers." data-confirm-title="Archive meter?" data-confirm-label="Archive" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Archive</button></form>
            @endif
            @if (count($m['entitlements']) === 0 && !$m['has_usage'])
                <form method="POST" action="{{ route('billing.meters.destroy', $m['id']) }}" style="margin:0"
                      data-confirm="Delete {{ $m['name'] }}? This cannot be undone." data-confirm-title="Delete meter?" data-confirm-label="Delete" data-confirm-variant="destructive">
                    @csrf @method('DELETE')
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
                </form>
            @endif
        </div>
    </header>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Definition</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Key</dt><dd class="num">{{ $m['key'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Display label</dt><dd>{{ $m['display'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Unit</dt><dd>{{ $m['unit'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Aggregation</dt><dd><span class="cbx-pill cbx-pill--info">{{ $m['aggregation'] }}</span></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Recorded usage</dt><dd>{{ $m['has_usage'] ? 'yes' : 'none' }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel" style="padding:16px 20px">
            <div class="mut" style="font-size:12px">Referencing entitlements</div>
            <div class="num" style="font-size:24px;font-weight:600">{{ number_format(count($m['entitlements'])) }}</div>
            <p class="mut" style="font-size:12px;margin:8px 0 0">A meter referenced by an entitlement (or with recorded usage) is archived, never hard-deleted, so its historical billing policy keeps resolving.</p>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Used by plans</h2></header>
        <table class="tbl">
            <thead><tr><th>Plan</th><th style="width:160px">Product</th><th style="width:140px">Allowance</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($m['entitlements'] as $ent)
                    <tr @if($ent['plan_id'])data-href="{{ route('billing.plans.show', $ent['plan_id']) }}" tabindex="0" role="link" aria-label="Open plan {{ $ent['plan'] }}"@endif>
                        <td style="font-weight:500">{{ $ent['plan'] }}</td>
                        <td class="mut">{{ $ent['product'] }}</td>
                        <td class="num">@if(!$ent['enabled'])<span class="cbx-pill cbx-pill--muted">off</span>@elseif($ent['unlimited'])∞@else{{ number_format($ent['allowance']) }}@endif</td>
                        <td class="rowchev">@if($ent['plan_id'])@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="padding:0"><div class="cbx-empty"><h3>Not referenced yet.</h3><p>No plan entitlement uses this meter — it can be deleted outright.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
