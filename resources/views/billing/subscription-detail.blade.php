@extends('layouts.app')
@section('title', 'Subscription')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Subscriptions', 'href' => route('billing.subscriptions')],
        ['label' => $subscription['org'] ?? 'Subscription'],
    ]" />
@endsection

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'paused' => 'muted', 'non_renewing' => 'warning', 'canceled' => 'muted'];
    $statusLabel = ['active' => 'active', 'trialing' => 'trial', 'past_due' => 'past due', 'paused' => 'paused', 'non_renewing' => 'non-renewing', 'canceled' => 'canceled'];
    $invStatusPill = ['paid' => 'success', 'open' => 'warning', 'void' => 'muted', 'draft' => 'muted', 'uncollectible' => 'destructive'];
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
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    // Render the real per-interval suffix ("/mo" vs "/yr"), never a hardcoded "/mo".
    $intervalUnit = static fn (string $interval): string => $interval === 'year' ? '/yr' : '/mo';
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.subscriptions')" label="Back to subscriptions" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div style="display:flex;align-items:center;gap:12px">
            <span class="avatar-sm" style="width:36px;height:36px;font-size:13px">{{ $s['ini'] }}</span>
            <div>
                <h1 class="cbx-page-title" style="font-size:20px">{{ $s['org'] }}</h1>
                <p class="cbx-page-desc" style="font-size:13px">{{ $s['plan'] }} · {{ MoneyFormatter::minor($s['minor'], $s['currency']) }} {{ $intervalUnit($s['interval']) }}
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
        <section class="cbx-panel" style="border-left:3px solid {{ $sunset->unresolved ? 'var(--destructive)' : 'var(--warning)' }}">
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

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Plan &amp; period</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>@if (!empty($s['plan_id']))<a class="cbx-link" href="{{ route('billing.plans.show', $s['plan_id']) }}">{{ $s['plan'] }}</a>@else{{ $s['plan'] }}@endif · {{ $s['interval'] }}ly</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>MRR</dt><dd class="num">{{ MoneyFormatter::minor($s['minor'], $s['currency']) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Seats</dt><dd class="num">{{ $s['seats'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Current period</dt><dd class="num">{{ $s['period_start'] }} → {{ $s['period_end'] }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Renewal</dt><dd class="num">{{ $s['renews'] }}</dd></div>
                @if (!empty($s['coupon']))
                    <div class="cbx-kv" style="padding:9px 0"><dt>Coupon</dt><dd>
                        @if (!empty($s['coupon']['id']))<a class="cbx-link num" href="{{ route('billing.coupons.show', $s['coupon']['id']) }}">{{ $s['coupon']['label'] }}</a>@else<span class="num">{{ $s['coupon']['label'] }}</span>@endif
                        <span class="cbx-pill {{ $s['coupon']['applies_now'] ? 'cbx-pill--success' : 'cbx-pill--muted' }}" style="margin-left:6px">{{ $s['coupon']['duration'] }}</span>
                        @if ($s['coupon']['remaining_periods'] !== null)
                            <span class="mut" style="font-size:11px">· {{ $s['coupon']['remaining_periods'] }} period(s) left</span>
                        @endif
                    </dd></div>
                @endif
                @if (!empty($s['test_clock']))
                    <div class="cbx-kv" style="padding:9px 0"><dt>Test clock</dt><dd><a class="cbx-link" href="{{ route('billing.test-mode.clocks.show', $s['test_clock']['id']) }}">{{ $s['test_clock']['name'] }}</a></dd></div>
                @endif
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

    {{-- This subscription's own invoices — cross-linked to the invoice detail. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Invoices</h2><span class="mut num" style="font-size:12px">{{ count($s['invoices']) }}</span></header>
        <table class="tbl">
            <thead><tr><th style="width:180px">Invoice</th><th style="width:120px">Issued</th><th class="right" style="width:150px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($s['invoices'] as $inv)
                    <tr data-href="{{ route('billing.invoices.show', $inv['id']) }}" tabindex="0" role="link" aria-label="Open invoice {{ $inv['number'] }}">
                        <td class="num">{{ $inv['number'] }}</td>
                        <td class="num mut">{{ $inv['issued'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($inv['total_minor'], $inv['currency']) }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $invStatusPill[$inv['status']] ?? 'muted' }}">{{ $inv['status'] }}</span></td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'invoice', 'size' => 18, 'sw' => 1.7])</div><h3>No invoices yet.</h3><p>Invoices raised for this subscription will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

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
                <form method="POST" action="{{ route('billing.subscriptions.seats.set', $s['id']) }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end"
                      data-confirm="Update purchased {{ $fullLabel }} seats for {{ $s['org'] }}? Buying prorates a charge now; releasing credits the balance." data-confirm-title="Change purchased seats?" data-confirm-label="Update seats" data-confirm-variant="primary">
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
                                <form method="POST" action="{{ route('billing.subscriptions.seats.unassign', $s['id']) }}" style="margin:0"
                                      data-confirm="Unassign {{ $member['subject'] }}'s {{ $fullLabel }} seat? They become a {{ $lightLabel }} (free) member and lose {{ $fullLabel }} access." data-confirm-title="Unassign seat?" data-confirm-label="Unassign seat">
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
            @if (!empty($d['retrying']))
                <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <form method="POST" action="{{ route('billing.subscriptions.dunning.retry', $d['id']) }}"
                          data-confirm="Retry the charge now?" data-confirm-title="Retry now?" data-confirm-label="Retry" data-confirm-variant="primary">
                        @csrf<button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Retry now</button>
                    </form>
                    <form method="POST" action="{{ route('billing.subscriptions.dunning.stop', $d['id']) }}" style="display:flex;gap:6px;align-items:center"
                          data-confirm="Stop dunning for this subscription?" data-confirm-title="Stop dunning?" data-confirm-label="Stop" data-confirm-variant="destructive">
                        @csrf
                        <select name="terminal" aria-label="Terminal action" style="{{ $inputStyle }};height:28px">
                            <option value="keep">leave past due</option>
                            <option value="cancel">cancel subscription</option>
                        </select>
                        <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Stop dunning</button>
                    </form>
                </div>
            @endif
        </section>
    @endif

    {{-- Retention actions over the App-A ManagesRetention contract --}}
    @if ($s['status'] !== 'canceled')
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Retention</h2></header>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:14px">
                <form method="POST" action="{{ route('billing.subscriptions.cancel', $s['id']) }}" id="retention-form" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end"
                      data-confirm="The subscription stays active until the period end, then cancels." data-confirm-title="Schedule cancellation?" data-confirm-label="Apply" data-confirm-variant="destructive">
                    @csrf
                    <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:var(--muted-foreground)">Action
                        <select name="mode" id="retention-mode" class="num" style="height:32px;min-width:170px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 8px;font-family:var(--font-sans)">
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
                {{-- Reflect the chosen action's real consequence in the shared confirm dialog. --}}
                <script>
                (function(){
                    var f = document.getElementById('retention-form'), m = document.getElementById('retention-mode');
                    if (!f || !m) return;
                    var org = @json($s['org']);
                    var map = {
                        immediate: { t: 'Cancel immediately?', b: 'Access ends now for ' + org + '. This cannot be undone and the current period is not refunded automatically.', l: 'Cancel now', v: 'destructive' },
                        period_end: { t: 'Schedule cancellation?', b: 'The subscription stays active until the period end, then cancels. You can reactivate before then.', l: 'Schedule cancel', v: 'destructive' },
                        pause: { t: 'Pause subscription?', b: 'Billing pauses for ' + org + '. You can resume at any time.', l: 'Pause', v: 'primary' }
                    };
                    function sync(){ var c = map[m.value] || map.period_end; f.setAttribute('data-confirm', c.b); f.setAttribute('data-confirm-title', c.t); f.setAttribute('data-confirm-label', c.l); f.setAttribute('data-confirm-variant', c.v); }
                    m.addEventListener('change', sync); sync();
                })();
                </script>
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

    {{-- Scheduled (change-at-period-end) plan change awaiting enactment --}}
    @if (!empty($s['pending_change']))
        <section class="cbx-panel" style="border-left:3px solid var(--warning)">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div>
                    <h2 class="cbx-panel-title" style="font-size:14px">Scheduled change</h2>
                    <p class="cbx-panel-desc" style="font-size:12px">Moves to <strong>{{ $s['pending_change']['plan'] }}</strong> on {{ $s['pending_change']['effective_at'] }} (at period end).</p>
                </div>
                <form method="POST" action="{{ route('billing.subscriptions.scheduled-change.cancel', $s['id']) }}"
                      data-confirm="Cancel the scheduled change to {{ $s['pending_change']['plan'] }}? The subscription stays on its current plan."
                      data-confirm-title="Cancel scheduled change?" data-confirm-label="Cancel change" data-confirm-variant="destructive">
                    @csrf
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Cancel scheduled change</button>
                </form>
            </header>
        </section>
    @endif

    {{-- Operator lifecycle (Wave 3): plan change / quantity / add-ons — each preview→confirm. --}}
    @if (!empty($s['serving']))
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Change plan</h2></header>
            <div style="padding:16px 20px">
                @if (!empty($s['available_plans']))
                    <form method="POST" action="{{ route('billing.subscriptions.plan-change.preview', $s['id']) }}" class="cbx-grid-3" style="gap:10px;align-items:end">
                        @csrf
                        <label style="{{ $labelStyle }}">New plan
                            <select name="plan" required style="{{ $inputStyle }}">
                                @foreach ($s['available_plans'] as $p)
                                    <option value="{{ $p['key'] }}">{{ $p['name'] }} · {{ MoneyFormatter::minor($p['minor'], $s['currency']) }}{{ $intervalUnit($p['interval'] ?? $s['interval']) }}</option>
                                @endforeach
                            </select></label>
                        <label style="{{ $labelStyle }}">When
                            <select name="when" style="{{ $inputStyle }}">
                                <option value="now">Immediately (prorated)</option>
                                <option value="period_end">At period end</option>
                            </select></label>
                        <div><button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Preview change →</button></div>
                    </form>
                @else
                    <p class="mut" style="font-size:13px">No other plans are priced in {{ $s['currency'] }}.</p>
                @endif
            </div>
        </section>

        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Change quantity</h2></header>
            <div style="padding:16px 20px">
                <form method="POST" action="{{ route('billing.subscriptions.quantity.preview', $s['id']) }}" class="cbx-grid-3" style="gap:10px;align-items:end">
                    @csrf
                    <label style="{{ $labelStyle }}">Billed quantity (currently {{ $s['seats'] }})
                        <input type="number" name="seats" min="1" value="{{ $s['seats'] }}" required class="num" style="{{ $inputStyle }}"></label>
                    <div><button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Preview reprice →</button></div>
                </form>
            </div>
        </section>

        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Add-ons</h2></header>
            @if (!empty($s['add_ons']))
                <table class="tbl">
                    <thead><tr><th>Key</th><th class="right" style="width:150px">Price</th><th style="width:120px">Alignment</th><th class="right" style="width:120px">Allotment</th><th style="width:100px"></th></tr></thead>
                    <tbody>
                        @foreach ($s['add_ons'] as $addOn)
                            <tr style="cursor:default">
                                <td class="num">{{ $addOn['key'] }}</td>
                                <td class="right num">{{ MoneyFormatter::minor($addOn['price_minor'], $addOn['currency']) }}</td>
                                <td>{{ $addOn['alignment'] }}</td>
                                <td class="right num">{{ $addOn['credit_allotment'] }}</td>
                                <td>
                                    <form method="POST" action="{{ route('billing.subscriptions.addons.remove', $s['id']) }}"
                                          data-confirm="Remove add-on “{{ $addOn['key'] }}”?" data-confirm-title="Remove add-on?" data-confirm-label="Remove" data-confirm-variant="destructive">
                                        @csrf<input type="hidden" name="key" value="{{ $addOn['key'] }}">
                                        <button type="submit" class="cbx-btn cbx-btn--ghost cbx-btn--sm" style="color:var(--destructive)">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <div style="padding:16px 20px;border-top:1px solid var(--border)">
                <form method="POST" action="{{ route('billing.subscriptions.addons.preview', $s['id']) }}" class="cbx-grid-3" style="gap:10px;align-items:end">
                    @csrf
                    <label style="{{ $labelStyle }}">Key
                        <input name="key" required maxlength="120" placeholder="extra-support" style="{{ $inputStyle }}"></label>
                    <label style="{{ $labelStyle }}">Price ({{ $s['currency'] }} minor units)
                        <input type="number" name="price_minor" min="0" required value="0" class="num" style="{{ $inputStyle }}"></label>
                    <label style="{{ $labelStyle }}">Credit allotment
                        <input type="number" name="credit_allotment" min="0" value="0" class="num" style="{{ $inputStyle }}"></label>
                    <input type="hidden" name="currency" value="{{ $s['currency'] }}">
                    <input type="hidden" name="alignment" value="aligned">
                    <div><button type="submit" class="cbx-btn cbx-btn--secondary cbx-btn--sm">Preview add-on →</button></div>
                </form>
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
