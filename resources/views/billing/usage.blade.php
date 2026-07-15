@extends('layouts.app')
@section('title', 'Usage')
@section('crumb', 'Usage')

@php
    $barColor = [
        'ok' => 'var(--success)',
        'warn' => 'var(--warning)',
        'over' => 'var(--destructive)',
        'unlimited' => 'var(--info)',
        'disabled' => 'var(--border-strong)',
    ];
    $rows = $selectedOrg ? $organizations->where('org_id', $selectedOrg) : $organizations;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Usage</h1>
            <p class="cbx-page-desc" style="font-size:13px">Used vs. allowance per meter · reconciled from the immutable event log</p>
        </div>
    </header>

    <div class="filters">
        <button class="fchip {{ $selectedOrg ? '' : 'set' }}" onclick="window.location='{{ route('billing.usage') }}'">All organizations</button>
        @foreach ($organizations as $org)
            <button class="fchip {{ $selectedOrg === $org['org_id'] ? 'set' : '' }}" onclick="window.location='{{ route('billing.usage', ['org' => $org['org_id']]) }}'">{{ $org['org'] }}</button>
        @endforeach
    </div>

    @foreach ($rows as $org)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div style="display:flex;align-items:center;gap:10px"><span class="avatar-sm" style="width:22px;height:22px;font-size:9px">{{ $org['ini'] }}</span><h2 class="cbx-panel-title" style="font-size:14px">{{ $org['org'] }}</h2></div>
                <span class="num mut" style="font-size:11px">{{ $org['period_start'] }} → {{ $org['period_end'] }}</span>
            </header>
            <div style="padding:6px 20px 16px">
                @foreach ($org['meters'] as $meter)
                    <div style="padding:10px 0;border-bottom:1px solid color-mix(in oklch, var(--border) 70%, transparent)">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">
                            <span style="font-size:13px;font-weight:500">{{ $meter['name'] }}
                                @if($meter['state'] === 'over')<span class="cbx-pill cbx-pill--destructive" style="margin-left:6px">{{ number_format($meter['overage']) }} over</span>@endif
                                @if(!$meter['enabled'])<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">disabled</span>@endif
                            </span>
                            <span class="num mut" style="font-size:12px">
                                {{ number_format($meter['used']) }}
                                @if($meter['unlimited']) / ∞ @elseif($meter['allowance'] !== null) / {{ number_format($meter['allowance']) }} @endif
                                {{ $meter['unit'] }}
                            </span>
                        </div>
                        <div style="height:6px;border-radius:9999px;background:var(--secondary);overflow:hidden">
                            <div style="height:100%;width:{{ $meter['percent'] }}%;background:{{ $barColor[$meter['state']] ?? 'var(--muted-foreground)' }};border-radius:9999px"></div>
                        </div>
                    </div>
                @endforeach
                @if (count($org['meters']) === 0)
                    <p class="mut" style="padding:12px 0">No meters resolved for this organization.</p>
                @endif
            </div>
        </section>
    @endforeach
</div>
@endsection
