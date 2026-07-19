@extends('layouts.app')
@section('title', 'Dunning')
@section('crumb', 'Dunning')

@php
    use App\Billing\Support\MoneyFormatter;
    $retryPill = ['retrying' => 'warning', 'recovered' => 'success', 'exhausted' => 'destructive', 'stopped' => 'muted'];
    $ratePct = number_format($recovery['rate'] * 100, 1, ',', '.');
    $revenue = collect($recovery['revenue'])->map(fn ($m) => MoneyFormatter::money($m))->implode(' · ');
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Dunning</h1>
            <p class="cbx-page-desc" style="font-size:13px">Adaptive, decline-code-aware recovery of failed renewal charges &middot; classified by decline &amp; retried on a per-category curve</p>
        </div>
        <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.settings.dunning') }}">@include('partials.icon', ['name' => 'settings', 'size' => 14, 'sw' => 1.7]) Retry strategy</a>
    </header>

    @include('partials.flash')

    {{-- Recovery analytics — the payoff of adaptive dunning, over real retry rows --}}
    <div class="stats">
        <div>
            <p class="lbl">Recovery rate</p>
            <p class="val">{{ $ratePct }}%</p>
            <span class="delta mut num">{{ $recovery['recovered'] }} of {{ $recovery['entered'] }} recovered</span>
        </div>
        <div>
            <p class="lbl">Revenue recovered</p>
            <p class="val" style="font-size:20px">{{ $revenue !== '' ? $revenue : '—' }}</p>
            <span class="delta mut num">involuntary churn averted</span>
        </div>
        <div>
            <p class="lbl">Avg attempts to recover</p>
            <p class="val">{{ number_format($recovery['avg_attempts'], 2, ',', '.') }}</p>
            <span class="delta mut num">{{ $recovery['active'] }} in flight</span>
        </div>
        <div>
            <p class="lbl">Churn averted</p>
            <p class="val">{{ $recovery['averted'] }}<span class="mut" style="font-size:13px;font-weight:400"> saved</span></p>
            <span class="delta {{ $recovery['exhausted'] > 0 ? 'warn' : 'mut' }} num">{{ $recovery['exhausted'] }} lost to non-payment</span>
        </div>
    </div>

    {{-- Recovery by decline category — which declines recover, which are lost causes --}}
    @if (!empty($byCategory))
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><div><h2 class="cbx-panel-title" style="font-size:14px">Recovery by decline category</h2><p class="cbx-panel-desc" style="font-size:12px">how the adaptive strategy is performing per decline reason</p></div></header>
            <table class="tbl">
                <thead><tr><th>Category</th><th class="right" style="width:120px">Entered</th><th class="right" style="width:120px">Recovered</th><th style="width:220px">Recovery rate</th></tr></thead>
                <tbody>
                    @foreach ($byCategory as $c)
                        <tr>
                            <td><span class="cbx-pill cbx-pill--{{ $c['pill'] }}"><span class="dot"></span>{{ $c['label'] }}</span></td>
                            <td class="right num">{{ $c['entered'] }}</td>
                            <td class="right num">{{ $c['recovered'] }}</td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="flex:1;height:6px;border-radius:99px;background:var(--muted);overflow:hidden"><div style="height:100%;width:{{ round($c['rate'] * 100) }}%;background:var(--{{ $c['pill'] === 'muted' ? 'border' : $c['pill'] }})"></div></div>
                                    <span class="num mut" style="font-size:12px;width:44px;text-align:right">{{ number_format($c['rate'] * 100, 0) }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <form method="GET" action="{{ route('billing.subscriptions.dunning') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter by customer…" aria-label="Filter dunning"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.subscriptions.dunning') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $retries->total() }}{{ $search ? ' matching' : '' }} in dunning</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Customer</th><th style="width:140px">Invoice</th><th class="right" style="width:120px">Amount</th><th style="width:150px">Decline</th><th class="right" style="width:90px">Attempts</th><th style="width:110px">Next retry</th><th style="width:100px">Status</th><th style="width:220px">Actions</th></tr></thead>
            <tbody>
                @forelse ($retries as $r)
                    <tr @if($r['subscription_id']) data-href="{{ route('billing.subscriptions.show', $r['subscription_id']) }}" tabindex="0" role="link" aria-label="Open subscription for {{ $r['org'] }}" @else style="cursor:default" @endif>
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $r['ini'] }}</span>{{ $r['org'] }}</span></td>
                        <td class="num">@if (!empty($r['invoice_id']))<a class="cbx-link" href="{{ route('billing.invoices.show', $r['invoice_id']) }}">{{ $r['invoice'] }}</a>@else{{ $r['invoice'] }}@endif</td>
                        <td class="right num">{{ MoneyFormatter::minor($r['invoice_minor'], $r['currency']) }}</td>
                        <td>
                            <span class="cbx-pill cbx-pill--{{ $r['category_pill'] }}"><span class="dot"></span>{{ $r['category_label'] }}</span>
                            @if (!empty($r['decline_code']))<div class="num mut" style="font-size:11px;margin-top:3px">{{ $r['decline_code'] }}</div>@endif
                        </td>
                        <td class="right num">{{ $r['attempts'] }} / {{ $r['max_attempts'] }}</td>
                        <td class="num mut">{{ $r['next_attempt_at'] }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $retryPill[$r['status']] ?? 'muted' }}"><span class="dot"></span>{{ $r['status'] }}</span></td>
                        <td>
                            @if ($r['status'] === 'retrying')
                                <div style="display:flex;gap:6px;align-items:center">
                                    <form method="POST" action="{{ route('billing.subscriptions.dunning.retry', $r['id']) }}"
                                          data-confirm="Retry the charge for {{ $r['org'] }} now?" data-confirm-title="Retry now?" data-confirm-label="Retry" data-confirm-variant="primary">
                                        @csrf<button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Retry now</button>
                                    </form>
                                    <form method="POST" action="{{ route('billing.subscriptions.dunning.stop', $r['id']) }}" style="display:flex;gap:4px;align-items:center"
                                          data-confirm="Stop dunning for {{ $r['org'] }}? The retry schedule halts." data-confirm-title="Stop dunning?" data-confirm-label="Stop" data-confirm-variant="destructive">
                                        @csrf
                                        <select name="terminal" aria-label="Terminal action" style="height:28px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);font-size:12px">
                                            <option value="keep">leave past due</option>
                                            <option value="cancel">cancel</option>
                                        </select>
                                        <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Stop</button>
                                    </form>
                                </div>
                            @else
                                <span class="mut" style="font-size:12px">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No accounts in dunning match “{{ $search }}”.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No failed charges in dunning.</h3><p>Renewals that fail to charge appear here while adaptive smart-retry chases them.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $retries->links('partials.pagination') }}
</div>
@endsection
