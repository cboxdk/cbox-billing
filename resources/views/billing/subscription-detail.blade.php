@extends('layouts.app')
@section('title', 'Subscription')
@section('crumb', 'Subscription')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'paused' => 'muted', 'non_renewing' => 'warning', 'canceled' => 'muted'];
    $statusLabel = ['active' => 'active', 'trialing' => 'trial', 'past_due' => 'past due', 'paused' => 'paused', 'non_renewing' => 'non-renewing', 'canceled' => 'canceled'];
    $s = $subscription;
    $overagePill = ['bill' => 'info', 'block' => 'muted'];
    $retryPill = ['retrying' => 'warning', 'recovered' => 'success', 'exhausted' => 'destructive'];
    $modeLabel = ['immediate' => 'canceled (immediate)', 'period_end' => 'scheduled cancel', 'pause' => 'paused', 'reactivate' => 'reactivated'];
    $reasonOptions = [
        'too_expensive' => 'Too expensive',
        'missing_features' => 'Missing features',
        'switching_provider' => 'Switching provider',
        'no_longer_needed' => 'No longer needed',
        'technical_issues' => 'Technical issues',
        'other' => 'Other',
    ];
@endphp

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.subscriptions') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to subscriptions</a>

    @if (session('status'))
        <div class="cbx-pill cbx-pill--success" style="align-self:flex-start;padding:7px 12px"><span class="dot"></span>{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="cbx-pill cbx-pill--destructive" style="align-self:flex-start;padding:7px 12px">{{ session('error') }}</div>
    @endif

    <header class="cbx-page-header">
        <div style="display:flex;align-items:center;gap:12px">
            <span class="avatar-sm" style="width:36px;height:36px;font-size:13px">{{ $s['ini'] }}</span>
            <div>
                <h1 class="cbx-page-title" style="font-size:20px">{{ $s['org'] }}</h1>
                <p class="cbx-page-desc" style="font-size:13px">{{ $s['plan'] }} · {{ MoneyFormatter::minor($s['minor'], $s['currency']) }} / mo
                    @if ($s['status'] === 'trialing' && !empty($s['trial_ends'])) · trial ends {{ $s['trial_ends'] }}
                    @elseif ($s['status'] === 'paused' && !empty($s['paused_at'])) · paused since {{ $s['paused_at'] }}
                    @elseif ($s['status'] === 'non_renewing') · cancels {{ $s['period_end'] }}
                    @endif
                </p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            @php($v = $statusPill[$s['status']] ?? 'muted')
            <span class="cbx-pill cbx-pill--{{ $v }}"><span class="dot"></span>{{ $statusLabel[$s['status']] ?? $s['status'] }}</span>
            <a class="cbx-btn cbx-btn--secondary cbx-btn--sm" href="{{ route('billing.customers.show', $s['org_id']) }}">View customer</a>
        </div>
    </header>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Plan &amp; period</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>{{ $s['plan'] }} · {{ $s['interval'] }}ly</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>MRR</dt><dd class="num">{{ MoneyFormatter::minor($s['minor'], $s['currency']) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Seats</dt><dd class="num">{{ $s['seats'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Current period</dt><dd class="num">{{ $s['period_start'] }} → {{ $s['period_end'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Renewal</dt><dd class="num">{{ $s['renews'] }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Included credits</h2></header>
            <table class="tbl">
                <thead><tr><th>Pool</th><th>Cadence</th><th class="right">Amount</th></tr></thead>
                <tbody>
                    @forelse ($s['credits'] as $credit)
                        <tr><td style="font-weight:500">{{ $credit['pool'] }}</td><td class="mut">{{ $credit['cadence'] }}</td><td class="right num">{{ number_format($credit['amount']) }} {{ $credit['denomination'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="mut" style="padding:16px;text-align:center">No credit grants.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Metered entitlements</h2></header>
        <table class="tbl">
            <thead><tr><th>Meter</th><th class="right" style="width:160px">Allowance</th><th style="width:120px">Overage</th><th style="width:100px">Status</th></tr></thead>
            <tbody>
                @foreach ($s['entitlements'] as $ent)
                    <tr>
                        <td style="font-weight:500">{{ $ent['meter'] }}</td>
                        <td class="right num">@if(!$ent['enabled'])—@elseif($ent['unlimited'])unlimited @else {{ number_format($ent['allowance']) }} {{ $ent['unit'] }}@endif</td>
                        <td><span class="cbx-pill cbx-pill--{{ $overagePill[$ent['overage']] ?? 'muted' }}">{{ $ent['overage'] }}</span></td>
                        <td>@if($ent['enabled'])<span class="cbx-pill cbx-pill--success"><span class="dot"></span>on</span>@else<span class="cbx-pill cbx-pill--muted">off</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Dunning: the smart-retry state for a failed renewal charge (App-A PaymentRetry) --}}
    @if (!empty($s['dunning']))
        @php($d = $s['dunning'])
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">Dunning</h2><p class="cbx-panel-desc" style="font-size:12px">smart-retry on invoice {{ $d['invoice'] }}</p></div>
                <span class="cbx-pill cbx-pill--{{ $retryPill[$d['status']] ?? 'muted' }}"><span class="dot"></span>{{ $d['status'] }}</span>
            </header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Attempts</dt><dd class="num">{{ $d['attempts'] }} of {{ $d['max_attempts'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>First failed</dt><dd class="num">{{ $d['first_failed_at'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Next retry</dt><dd class="num">{{ $d['next_attempt_at'] }}</dd></div>
            </dl>
        </section>
    @endif

    {{-- Retention actions over the App-A ManagesRetention contract --}}
    @if ($s['status'] !== 'canceled')
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Retention</h2></header>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:14px">
                <form method="POST" action="{{ route('billing.subscriptions.cancel', $s['id']) }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                    @csrf
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted-foreground)">Action
                        <select name="mode" class="num" style="height:32px;min-width:170px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 8px;font-family:var(--font-sans)">
                            <option value="period_end">Cancel at period end</option>
                            <option value="immediate">Cancel immediately</option>
                            <option value="pause">Pause instead</option>
                        </select>
                    </label>
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted-foreground)">Reason
                        <select name="reason" style="height:32px;min-width:190px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 8px;font-family:var(--font-sans)">
                            <option value="">—</option>
                            @foreach ($reasonOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <input name="feedback" placeholder="Feedback (optional)" style="height:32px;flex:1;min-width:200px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 10px;font-family:var(--font-sans);font-size:13px">
                    <button type="submit" class="cbx-btn cbx-btn--destructive cbx-btn--sm">@include('partials.icon', ['name' => 'x', 'size' => 14, 'sw' => 1.7]) Apply</button>
                </form>
                @if ($s['reactivatable'])
                    <form method="POST" action="{{ route('billing.subscriptions.reactivate', $s['id']) }}" style="margin:0">
                        @csrf
                        <button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">@include('partials.icon', ['name' => 'rotate', 'size' => 14, 'sw' => 1.7]) Reactivate / resume</button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    {{-- Captured retention events (append-only churn log) --}}
    @if (!empty($s['cancellations']))
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Cancellation reasons</h2></header>
            <table class="tbl">
                <thead><tr><th style="width:120px">Date</th><th style="width:150px">Event</th><th style="width:160px">Reason</th><th>Feedback</th></tr></thead>
                <tbody>
                    @foreach ($s['cancellations'] as $event)
                        <tr style="cursor:default">
                            <td class="num mut">{{ $event['at'] }}</td>
                            <td><span class="cbx-pill cbx-pill--muted">{{ $modeLabel[$event['mode']] ?? $event['mode'] }}</span></td>
                            <td>{{ $event['reason'] ? ($reasonOptions[$event['reason']] ?? $event['reason']) : '—' }}</td>
                            <td class="mut">{{ $event['feedback'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
