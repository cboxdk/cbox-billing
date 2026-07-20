@extends('layouts.app')
@section('title', $feature['name'] ?? 'Feature')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Features', 'href' => route('billing.features')],
        ['label' => $feature['name'] ?? 'Feature'],
    ]" />
@endsection

@php($f = $feature)

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.features')" label="Back to features" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $f['name'] }}
                @if ($f['archived'])<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">archived</span>@endif
                <span class="cbx-pill cbx-pill--{{ $f['type'] === 'config' ? 'info' : 'muted' }}" style="margin-left:2px">{{ $f['type'] }}</span>
            </h1>
            <p class="cbx-page-desc num" style="font-size:13px">{{ $f['key'] }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <a href="{{ route('billing.features.edit', $f['id']) }}" class="cbx-btn cbx-btn--sm">Edit</a>
            @if ($f['archived'])
                <form method="POST" action="{{ route('billing.features.unarchive', $f['id']) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Reinstate</button></form>
            @else
                <form method="POST" action="{{ route('billing.features.archive', $f['id']) }}" style="margin:0"
                      data-confirm="Archive {{ $f['name'] }}? Existing plan grants keep resolving; it is hidden from new-grant pickers." data-confirm-title="Archive feature?" data-confirm-label="Archive" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Archive</button></form>
            @endif
            @if (count($f['grants']) === 0)
                <form method="POST" action="{{ route('billing.features.destroy', $f['id']) }}" style="margin:0"
                      data-confirm="Delete {{ $f['name'] }}? This cannot be undone." data-confirm-title="Delete feature?" data-confirm-label="Delete" data-confirm-variant="destructive">
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
                <div class="cbx-kv" style="padding:9px 0"><dt>Key</dt><dd class="num">{{ $f['key'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Type</dt><dd><span class="cbx-pill cbx-pill--{{ $f['type'] === 'config' ? 'info' : 'muted' }}">{{ $f['type'] }}</span></dd></div>
                @if ($f['type'] === 'config')
                    <div class="cbx-kv" style="padding:9px 0"><dt>Value type</dt><dd>{{ $f['value_type'] ?? '—' }}</dd></div>
                @endif
                <div class="cbx-kv" style="padding:9px 0"><dt>Description</dt><dd>{{ $f['description'] ?? '—' }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel" style="padding:16px 20px">
            <div class="mut" style="font-size:12px">Granting plans</div>
            <div class="num" style="font-size:24px;font-weight:600">{{ number_format(count($f['grants'])) }}</div>
            <p class="mut" style="font-size:12px;margin:8px 0 0">The `key` aligns with the on-prem license entitlement vocabulary, so a hosted subscription and a self-hosted license gate on the same name. A referenced feature is archived, never hard-deleted, so its grants keep resolving.</p>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Granted by plans</h2></header>
        <table class="tbl">
            <thead><tr><th>Plan</th><th style="width:160px">Product</th><th style="width:120px">Grant</th><th style="width:120px">Value</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($f['grants'] as $grant)
                    <tr @if($grant['plan_id'])data-href="{{ route('billing.plans.show', $grant['plan_id']) }}" tabindex="0" role="link" aria-label="Open plan {{ $grant['plan'] }}"@endif>
                        <td style="font-weight:500">{{ $grant['plan'] }}</td>
                        <td class="mut">{{ $grant['product'] }}</td>
                        <td>@if($grant['enabled'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>granted</span>@else<span class="cbx-pill cbx-pill--muted">off</span>@endif</td>
                        <td class="num">{{ $grant['value'] ?? '—' }}</td>
                        <td class="rowchev">@if($grant['plan_id'])@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>Not granted yet.</h3><p>No plan grants this feature — it can be deleted outright. Grant it from a plan's detail page.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
