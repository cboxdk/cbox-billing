@extends('layouts.app')
@section('title', 'Customer')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Customers', 'href' => route('billing.customers')],
        ['label' => $customer['org'] ?? 'Customer'],
    ]" />
@endsection

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning', 'canceled' => 'muted', 'none' => 'muted'];
    $standingPill = ['good' => 'success', 'disputed' => 'warning', 'suspended' => 'destructive'];
    $invStatusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted', 'void' => 'muted'];
    $c = $customer;
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.customers') }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to customers</a>

    @include('partials.flash')

    <header class="cbx-page-header">
        <div style="display:flex;align-items:center;gap:12px">
            <span class="avatar-sm" style="width:36px;height:36px;font-size:13px">{{ $c['ini'] }}</span>
            <div>
                <h1 class="cbx-page-title" style="font-size:20px">{{ $c['org'] }}</h1>
                <p class="cbx-page-desc num" style="font-size:13px">{{ $c['id'] }}</p>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--{{ $standingPill[$c['standing']] ?? 'muted' }}">standing: {{ $c['standing'] }}</span>
        </div>
    </header>

    <div class="cbx-grid-2">
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Account</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Billing email</dt><dd>{{ $c['billing_email'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Country</dt><dd>{{ $c['billing_country'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Tax ID</dt><dd>{{ $c['tax_id'] ?? '—' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Currency</dt><dd class="num">{{ $c['currency'] }}</dd></div>
            </dl>
        </section>
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Subscription</h2></header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>{{ $c['plan'] ?? 'No active subscription' }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Status</dt><dd><span class="cbx-pill cbx-pill--{{ $statusPill[$c['status']] ?? 'muted' }}">{{ $c['status'] === 'none' ? 'no sub' : $c['status'] }}</span></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>MRR</dt><dd class="num">{{ MoneyFormatter::minor($c['mrr'], $c['currency']) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Outstanding</dt><dd class="num">{{ $c['outstanding_label'] }}</dd></div>
            </dl>
        </section>
    </div>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Invoices</h2></header>
        <table class="tbl">
            <thead><tr><th style="width:170px">Invoice</th><th style="width:110px">Date</th><th class="right" style="width:150px">Amount</th><th style="width:100px">Status</th><th style="width:36px"></th></tr></thead>
            <tbody>
                @forelse ($c['invoices'] as $inv)
                    <tr data-href="{{ route('billing.invoices.show', $inv['id']) }}" tabindex="0" role="link" aria-label="Open invoice {{ $inv['number'] }}">
                        <td class="num">{{ $inv['number'] }}</td>
                        <td class="num mut">{{ $inv['date'] }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($inv['minor'], $inv['currency']) }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $invStatusPill[$inv['status']] ?? 'muted' }}">@if($inv['status'] !== 'draft')<span class="dot"></span>@endif{{ $inv['status'] }}</span></td>
                        <td class="rowchev">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut" style="padding:20px;text-align:center">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Wallet / credits (Wave 3): per-pool balances over the engine wallet + the ledger. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Wallet &amp; credits</h2>
            <p class="cbx-panel-desc" style="font-size:12px">Balances are derived from the engine wallet lots — never stored loose.</p>
        </header>

        @if (!empty($wallet['pools']))
            <div style="display:flex;flex-wrap:wrap;gap:12px;padding:16px 20px">
                @foreach ($wallet['pools'] as $pool)
                    <div style="min-width:150px;border:1px solid var(--border);border-radius:10px;padding:10px 14px">
                        <div class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em">{{ $pool['pool'] }} · {{ $pool['denomination'] }}</div>
                        <div class="num" style="font-size:20px;font-weight:600">{{ number_format($pool['balance']) }}</div>
                        <div class="mut" style="font-size:11px">{{ $pool['spendable'] ? 'spendable' : 'tracked' }}@if($pool['forfeits']) · forfeits on cancel @endif</div>
                    </div>
                @endforeach
            </div>
        @else
            <div style="padding:16px 20px" class="mut">No wallet activity yet.</div>
        @endif

        {{-- Operator credit adjustment --}}
        <div style="padding:16px 20px;border-top:1px solid var(--border)">
            <form method="POST" action="{{ route('billing.customers.wallet.adjust', $organization->id) }}" class="cbx-grid-3" style="gap:10px;align-items:end"
                  data-confirm="Apply this wallet adjustment for {{ $c['org'] }}?" data-confirm-title="Adjust wallet?" data-confirm-label="Apply" data-confirm-variant="primary">
                @csrf
                <label style="{{ $labelStyle }}">Action
                    <select name="direction" style="{{ $inputStyle }}">
                        <option value="grant">Grant credit</option>
                        <option value="debit">Debit (correction)</option>
                    </select></label>
                <label style="{{ $labelStyle }}">Pool
                    <select name="pool" style="{{ $inputStyle }}">
                        <option value="promotional">Promotional (goodwill)</option>
                        <option value="purchased">Purchased (top-up)</option>
                        <option value="included">Included</option>
                    </select></label>
                <label style="{{ $labelStyle }}">Amount
                    <input type="number" name="amount" min="1" required value="100" class="num" style="{{ $inputStyle }}"></label>
                <label style="{{ $labelStyle }}">Denomination
                    <input name="denomination" value="credit" required maxlength="32" style="{{ $inputStyle }}"></label>
                <label style="{{ $labelStyle }}">Expiry (days, grants only)
                    <input type="number" name="expires_in_days" min="1" max="3650" placeholder="365" class="num" style="{{ $inputStyle }}"></label>
                <label style="{{ $labelStyle }}">Reason
                    <input name="reason" required maxlength="255" placeholder="Goodwill for outage" style="{{ $inputStyle }}"></label>
                <div><button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Apply adjustment</button></div>
            </form>
        </div>

        {{-- Grant / debit ledger --}}
        @if (!empty($wallet['lots']))
            <table class="tbl">
                <thead><tr><th>Pool</th><th>Denomination</th><th class="right" style="width:120px">Remaining</th><th style="width:120px">Kind</th><th style="width:120px">Granted</th><th style="width:120px">Expires</th></tr></thead>
                <tbody>
                    @foreach ($wallet['lots'] as $lot)
                        <tr style="cursor:default;{{ $lot['active'] ? '' : 'opacity:.55' }}">
                            <td>{{ $lot['pool'] }}</td>
                            <td class="num">{{ $lot['denomination'] }}</td>
                            <td class="right num">{{ number_format($lot['remaining']) }}</td>
                            <td class="mut">{{ $lot['kind'] }}</td>
                            <td class="num mut">{{ $lot['granted_at'] }}</td>
                            <td class="num mut">{{ $lot['expires_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Operator-adjustment audit trail --}}
        @if (!empty($wallet['adjustments']))
            <header class="cbx-panel-header" style="padding:12px 20px;border-top:1px solid var(--border)"><h2 class="cbx-panel-title" style="font-size:13px">Adjustment audit</h2></header>
            <table class="tbl">
                <thead><tr><th style="width:150px">When</th><th style="width:90px">Action</th><th>Pool</th><th class="right" style="width:120px">Amount</th><th>Reason</th><th style="width:180px">Operator</th></tr></thead>
                <tbody>
                    @foreach ($wallet['adjustments'] as $adj)
                        <tr style="cursor:default">
                            <td class="num mut">{{ $adj['at'] }}</td>
                            <td><span class="cbx-pill cbx-pill--{{ $adj['direction'] === 'grant' ? 'success' : 'warning' }}">{{ $adj['direction'] }}</span></td>
                            <td>{{ $adj['pool'] }}</td>
                            <td class="right num">{{ number_format($adj['amount']) }} {{ $adj['denomination'] }}</td>
                            <td>{{ $adj['reason'] }}</td>
                            <td class="mut">{{ $adj['actor'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
@endsection
