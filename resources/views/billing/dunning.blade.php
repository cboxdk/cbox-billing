@extends('layouts.app')
@section('title', 'Dunning')
@section('crumb', 'Dunning')

@php
    use App\Billing\Support\MoneyFormatter;
    $retryPill = ['retrying' => 'warning', 'recovered' => 'success', 'exhausted' => 'destructive', 'stopped' => 'muted'];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Dunning</h1>
            <p class="cbx-page-desc" style="font-size:13px">Failed renewal charges under smart-retry · attempts, next retry &amp; outcome</p>
        </div>
    </header>

    @include('partials.flash')

    <form method="GET" action="{{ route('billing.subscriptions.dunning') }}" class="filters" role="search">
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter by customer…" aria-label="Filter dunning"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ route('billing.subscriptions.dunning') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $retries->total() }}{{ $search ? ' matching' : '' }} in dunning</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Customer</th><th style="width:150px">Invoice</th><th class="right" style="width:130px">Amount</th><th class="right" style="width:100px">Attempts</th><th style="width:120px">Next retry</th><th style="width:110px">Status</th><th style="width:230px">Actions</th></tr></thead>
            <tbody>
                @forelse ($retries as $r)
                    <tr @if($r['subscription_id']) data-href="{{ route('billing.subscriptions.show', $r['subscription_id']) }}" tabindex="0" role="link" aria-label="Open subscription for {{ $r['org'] }}" @else style="cursor:default" @endif>
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $r['ini'] }}</span>{{ $r['org'] }}</span></td>
                        <td class="num">{{ $r['invoice'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($r['invoice_minor'], $r['currency']) }}</td>
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
                                        <select name="terminal" aria-label="Terminal action" style="height:28px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);font-size:12px">
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
                    <tr><td colspan="7" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No accounts in dunning match “{{ $search }}”.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No failed charges in dunning.</h3><p>Renewals that fail to charge appear here while smart-retry chases them.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $retries->links('partials.pagination') }}
</div>
@endsection
