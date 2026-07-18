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
    // The reason labels come from the bound retention survey (the app's basic default, or a
    // plugin's rich flow) — the same seam the cancel select renders from.
    $reasonOptions = [];
    foreach ($retentionReasons ?? [] as $reason) {
        $reasonOptions[$reason['key']] = $reason['label'];
    }
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

    {{-- Plan-sunset notice (ADR-0016): the subscription is on a retiring plan --}}
    @if (!empty($sunset))
        <section class="cbx-panel" style="border-left:3px solid {{ $sunset->unresolved ? 'var(--destructive)' : 'var(--warning, #b3651f)' }}">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div>
                    <h2 class="cbx-panel-title" style="font-size:14px">Plan retiring</h2>
                    <p class="cbx-panel-desc" style="font-size:12px">{{ $sunset->planName }} retires on {{ $sunset->retiresAt }}. Next renewal is {{ $sunset->renewalDue }} — the deadline to choose a new plan.</p>
                </div>
                @if ($sunset->unresolved)
                    <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>unresolved</span>
                @elseif ($sunset->election === 'successor')
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>successor chosen</span>
                @elseif ($sunset->election === 'cancel')
                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>cancel scheduled</span>
                @else
                    <span class="cbx-pill cbx-pill--muted"><span class="dot"></span>undecided</span>
                @endif
            </header>
            <div style="padding:12px 20px;font-size:13px;color:var(--muted-foreground)">
                @if ($sunset->unresolved)
                    <strong style="color:var(--destructive)">This subscription cannot renew on the retired plan and has no successor or default.</strong> It is blocked from charging until resolved.
                @elseif ($sunset->election === 'successor')
                    Moves to <strong>{{ $sunset->electedSuccessorName }}</strong> at the next renewal.
                @elseif ($sunset->election === 'cancel')
                    Cancels at the next renewal.
                @elseif ($sunset->hasDefault())
                    If the customer does nothing, they move to the default plan <strong>{{ $sunset->defaultSuccessorName }}</strong> at renewal.
                @else
                    No default is configured — the customer must choose a successor or cancel before renewal, or it is flagged unresolved.
                @endif
            </div>
        </section>
    @endif

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

    {{-- Seats (purchased + explicitly-assigned). Purchased Full seats ARE the billed quantity;
         Light members are eligible-but-unassigned and never billed. --}}
    @if (!empty($seats))
        @php($fullLabel = $seatTypes['full']['label'] ?? 'Full')
        @php($lightLabel = $seatTypes['light']['label'] ?? 'Light')
        @php($lightBillable = (bool) ($seatTypes['light']['billable'] ?? false))
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div>
                    <h2 class="cbx-panel-title" style="font-size:14px">Seats</h2>
                    <p class="cbx-panel-desc" style="font-size:12px">Purchased {{ $fullLabel }} seats are billed; {{ strtolower($lightLabel) }} members are free.</p>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <span class="cbx-pill cbx-pill--info"><span class="dot"></span>{{ $seats->fullCount() }} {{ $fullLabel }}</span>
                    <span class="cbx-pill cbx-pill--muted">{{ $seats->lightCount() }} {{ $lightLabel }}{{ $lightBillable ? '' : ' · free' }}</span>
                </div>
            </header>

            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Purchased seats</dt><dd class="num">{{ $seats->purchased }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Assigned ({{ $fullLabel }})</dt><dd class="num">{{ $seats->assigned }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Free to assign</dt><dd class="num">{{ $seats->free() }}</dd></div>
            </dl>

            {{-- Buy / release purchased seats (guardrailed: cannot drop below the assigned count) --}}
            <div style="padding:12px 20px;border-top:1px solid var(--border)">
                <form method="POST" action="{{ route('billing.subscriptions.seats.set', $s['id']) }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                    @csrf
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted-foreground)">Purchased {{ $fullLabel }} seats
                        <input type="number" name="seats" min="{{ max(1, $seats->assigned) }}" value="{{ $seats->purchased }}" class="num" style="height:32px;width:120px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 10px;font-family:var(--font-sans)">
                    </label>
                    <button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Buy / release</button>
                    <span class="mut" style="font-size:12px">Buying prorates a charge now; releasing credits the balance. Cannot drop below {{ $seats->assigned }} (assigned).</span>
                </form>
            </div>

            {{-- Assign a free seat to an eligible-but-unassigned member --}}
            <div style="padding:12px 20px;border-top:1px solid var(--border)">
                @if (!empty($seats->assignable) && $seats->free() > 0)
                    <form method="POST" action="{{ route('billing.subscriptions.seats.assign', $s['id']) }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                        @csrf
                        <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted-foreground)">Assign a seat to
                            <select name="subject" style="height:32px;min-width:220px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 8px;font-family:var(--font-sans)">
                                @foreach ($seats->assignable as $member)
                                    <option value="{{ $member['subject'] }}">{{ $member['subject'] }}@if(!empty($member['role'])) · {{ $member['role'] }}@endif</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Assign {{ $fullLabel }} seat</button>
                    </form>
                @elseif ($seats->free() <= 0)
                    <p class="mut" style="font-size:13px;margin:0">All purchased seats are assigned. Buy more seats to assign another member.</p>
                @else
                    <p class="mut" style="font-size:13px;margin:0">No eligible members are waiting for a seat.</p>
                @endif
            </div>

            {{-- Full members (assigned) --}}
            <table class="tbl">
                <thead><tr><th>{{ $fullLabel }} member (assigned)</th><th style="width:150px">Role</th><th style="width:110px">Source</th><th style="width:120px"></th></tr></thead>
                <tbody>
                    @forelse ($seats->full as $member)
                        <tr>
                            <td style="font-weight:500">{{ $member['subject'] }}</td>
                            <td class="mut">{{ $member['role'] ?: '—' }}</td>
                            <td><span class="cbx-pill cbx-pill--{{ ($member['source'] ?? 'manual') === 'auto' ? 'info' : 'muted' }}">{{ $member['source'] ?? 'manual' }}</span></td>
                            <td class="right">
                                <form method="POST" action="{{ route('billing.subscriptions.seats.unassign', $s['id']) }}" style="margin:0">
                                    @csrf
                                    <input type="hidden" name="subject" value="{{ $member['subject'] }}">
                                    <button type="submit" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Unassign</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="mut" style="padding:16px;text-align:center">No seats assigned yet.</td></tr>
                    @endforelse
                </tbody>
            </table>

            {{-- Light members (eligible, free) --}}
            @if (!empty($seats->light))
                <table class="tbl">
                    <thead><tr><th>{{ $lightLabel }} member (free)</th><th style="width:150px">Role</th></tr></thead>
                    <tbody>
                        @foreach ($seats->light as $member)
                            <tr>
                                <td style="font-weight:500">{{ $member['subject'] }}</td>
                                <td class="mut">{{ $member['role'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

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
                            @foreach ($retentionReasons as $reason)
                                <option value="{{ $reason['key'] }}">{{ $reason['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <input name="feedback" placeholder="Feedback (optional)" style="height:32px;flex:1;min-width:200px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 10px;font-family:var(--font-sans);font-size:13px">
                    <button type="submit" class="cbx-btn cbx-btn--destructive cbx-btn--sm">@include('partials.icon', ['name' => 'x', 'size' => 14, 'sw' => 1.7]) Apply</button>
                </form>
                {{-- Save-offers the bound retention seam presents (basic default: pause instead of cancel) --}}
                @if (!empty($retentionOffers))
                    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;border-top:1px solid var(--border);padding-top:12px">
                        <span class="mut" style="font-size:12px">Save offers</span>
                        @foreach ($retentionOffers as $offer)
                            <span class="cbx-pill cbx-pill--info" title="{{ $offer['type'] }}">{{ $offer['label'] }}</span>
                        @endforeach
                    </div>
                @endif
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
