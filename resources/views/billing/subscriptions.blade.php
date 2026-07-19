@extends('layouts.app')
@section('title', 'Subscriptions')
@section('crumb', 'Subscriptions')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'paused' => 'muted', 'non_renewing' => 'warning', 'canceled' => 'muted'];
    $statusLabel = ['active' => 'active', 'trialing' => 'trial', 'past_due' => 'past due', 'paused' => 'paused', 'non_renewing' => 'non-renewing', 'canceled' => 'canceled'];
    $tabs = [
        ['key' => null, 'label' => 'All', 'count' => $counts['all']],
        ['key' => 'active', 'label' => 'Active', 'count' => $counts['active']],
        ['key' => 'trialing', 'label' => 'Trials', 'count' => $counts['trialing']],
        ['key' => 'past_due', 'label' => 'Past due', 'count' => $counts['past_due']],
        ['key' => 'paused', 'label' => 'Paused', 'count' => $counts['paused']],
        ['key' => 'non_renewing', 'label' => 'Non-renewing', 'count' => $counts['non_renewing']],
        ['key' => 'canceled', 'label' => 'Canceled', 'count' => $counts['canceled']],
    ];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Subscriptions</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $counts['active'] }} active · {{ $counts['trialing'] }} trials · {{ $counts['past_due'] }} past due · {{ $counts['paused'] }} paused · {{ $counts['non_renewing'] }} non-renewing · {{ $counts['canceled'] }} canceled</p>
        </div>
        <a class="cbx-btn cbx-btn--primary cbx-btn--sm" href="{{ route('billing.subscriptions.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) New subscription</a>
    </header>

    @include('partials.flash')

    @if (!empty($unresolvedRetirements) && count($unresolvedRetirements) > 0)
        <div class="cbx-panel" style="padding:12px 20px;border-left:3px solid var(--destructive)">
            <strong style="color:var(--destructive)">{{ count($unresolvedRetirements) }} subscription{{ count($unresolvedRetirements) === 1 ? '' : 's' }} on a retired plan need resolution.</strong>
            <span class="mut" style="font-size:12px">
                @foreach ($unresolvedRetirements as $u)
                    <a href="{{ route('billing.subscriptions.show', $u['id']) }}" style="color:var(--foreground)">{{ $u['org'] }}</a>@if(!$loop->last), @endif
                @endforeach
            </span>
        </div>
    @endif

    <div class="cbx-tabs" style="min-height:40px;padding:4px 8px">
        <nav style="display:flex;flex:1;align-items:center;gap:2px">
            @foreach ($tabs as $tab)
                <a class="cbx-tab {{ $status === $tab['key'] ? 'cbx-tab--active' : '' }}"
                   href="{{ $tab['key'] ? route('billing.subscriptions', ['status' => $tab['key']]) : route('billing.subscriptions') }}"
                   style="padding:4px 9px">{{ $tab['label'] }}<span class="cbx-tab-count">{{ $tab['count'] }}</span></a>
            @endforeach
        </nav>
    </div>

    <form method="GET" action="{{ route('billing.subscriptions') }}" class="filters" role="search">
        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <div class="fsearch">@include('partials.icon', ['name' => 'search', 'size' => 14, 'sw' => 1.7])<input name="q" value="{{ $search }}" placeholder="Filter subscriptions…" aria-label="Filter subscriptions"><kbd class="k">F</kbd></div>
        @if ($search)
            <a href="{{ $status ? route('billing.subscriptions', ['status' => $status]) : route('billing.subscriptions') }}" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Clear</a>
        @endif
        <span style="margin-left:auto" class="num mut">{{ $subscriptions->total() }}{{ $search ? ' matching' : '' }} of {{ $counts['all'] }}</span>
    </form>

    <section class="cbx-panel">
        <table class="tbl">
            <thead><tr><th>Customer</th><th style="width:120px">Plan</th><th class="right" style="width:140px">MRR</th><th style="width:110px">Status</th><th style="width:140px">Renews</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($subscriptions as $sub)
                    <tr data-href="{{ route('billing.subscriptions.show', $sub['id']) }}" tabindex="0" role="link" aria-label="Open subscription for {{ $sub['org'] }}">
                        <td><span style="display:flex;align-items:center;gap:10px"><span class="avatar-sm">{{ $sub['ini'] }}</span><span><span style="display:block;font-weight:500">{{ $sub['org'] }}</span><span class="num mut" style="display:block;font-size:11px">since {{ $sub['started'] }}</span></span></span></td>
                        <td>{{ $sub['plan'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($sub['minor'], $sub['currency']) }}</td>
                        <td>
                            @php($v = $statusPill[$sub['status']] ?? 'muted')
                            <span class="cbx-pill cbx-pill--{{ $v }}"><span class="dot"></span>{{ $statusLabel[$sub['status']] ?? $sub['status'] }}</span>
                        </td>
                        <td class="num mut">{{ $sub['renews'] }}</td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0">
                        @if ($search)
                            <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'search', 'size' => 18, 'sw' => 1.7])</div><h3>No matches</h3><p>No subscriptions match “{{ $search }}” in this view. Try a different term or clear the filter.</p></div>
                        @else
                            <div class="cbx-empty"><h3>No subscriptions in this view.</h3><p>Subscriptions with this standing will appear here.</p></div>
                        @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{ $subscriptions->links('partials.pagination') }}
</div>
@endsection
