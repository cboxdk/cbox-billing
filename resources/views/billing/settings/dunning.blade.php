@extends('layouts.app')
@section('title', 'Retry strategy')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Retry strategy'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.subscriptions.dunning')" label="Back to dunning" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Adaptive retry strategy</h1>
            <p class="cbx-page-desc" style="font-size:13px">How each decline category is recovered — the retry count, backoff curve and timing heuristics. Overrides persist and are read live; a category with no override inherits the shipped defaults.</p>
        </div>
    </header>

    @include('partials.flash')

    <section class="cbx-panel" style="border-left:3px solid var(--border)">
        <div style="padding:14px 20px;display:flex;gap:24px;flex-wrap:wrap">
            <div><p class="lbl">Recovery window</p><p class="num" style="font-size:14px;margin:2px 0 0">{{ $window }} days max</p></div>
            <div><p class="lbl">Payday anchors</p><p class="num" style="font-size:14px;margin:2px 0 0">day {{ implode(', ', (array) $paydayDays) }}</p></div>
            <div><p class="lbl">Quiet weekdays</p><p class="num" style="font-size:14px;margin:2px 0 0">ISO {{ implode(', ', (array) $quietWeekdays) }}</p></div>
        </div>
    </section>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Decline category</th><th style="width:170px">Backoff (days)</th><th class="right" style="width:90px">Attempts</th><th style="width:180px">Heuristics</th><th style="width:110px">Source</th><th style="width:90px"></th></tr></thead>
            <tbody>
                @foreach ($strategies as $st)
                    <tr @if($st['editable']) data-href="{{ route('billing.settings.dunning.edit', $st['category']) }}" tabindex="0" role="link" aria-label="Edit {{ $st['label'] }} strategy" @else style="cursor:default" @endif>
                        <td>
                            <span class="cbx-pill cbx-pill--{{ $st['pill'] }}"><span class="dot"></span>{{ $st['label'] }}</span>
                            <div class="mut" style="font-size:11px;margin-top:4px;max-width:340px">{{ $st['description'] }}</div>
                        </td>
                        <td class="num">
                            @if ($st['retry']){{ implode(' · ', $st['backoff']) }}@else<span class="mut">no retries</span>@endif
                        </td>
                        <td class="right num">@if ($st['retry']){{ $st['max_attempts'] }}@else—@endif</td>
                        <td>
                            @if ($st['avoid_weekends'])<span class="cbx-pill cbx-pill--muted" style="margin:0 3px 3px 0">skip weekends</span>@endif
                            @if ($st['align_to_payday'])<span class="cbx-pill cbx-pill--muted" style="margin:0 3px 3px 0">payday-aware</span>@endif
                            @if (! $st['avoid_weekends'] && ! $st['align_to_payday'])<span class="mut" style="font-size:12px">—</span>@endif
                        </td>
                        <td>
                            @if ($st['overridden'])<span class="cbx-pill cbx-pill--info"><span class="dot"></span>custom</span>@else<span class="cbx-pill cbx-pill--muted">default</span>@endif
                        </td>
                        <td>
                            @if ($st['editable'])<a class="cbx-link" href="{{ route('billing.settings.dunning.edit', $st['category']) }}">Edit</a>@else<span class="mut" style="font-size:12px">fixed</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
