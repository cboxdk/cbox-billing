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
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.customers')" label="Back to customers" />

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
            @if (!empty($c['suspended']))<span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>suspended</span>@endif
            <span class="cbx-pill cbx-pill--{{ $standingPill[$c['standing']] ?? 'muted' }}">standing: {{ $c['standing'] }}</span>
            @if (!empty($c['suspended']))
                <form method="POST" action="{{ route('billing.customers.reactivate', $organization->id) }}" style="margin:0"
                      data-confirm="Reactivate {{ $c['org'] }}? Access is restored and the account returns to good standing."
                      data-confirm-title="Reactivate customer?" data-confirm-label="Reactivate" data-confirm-variant="primary">
                    @csrf<button type="submit" class="cbx-btn cbx-btn--sm">Reactivate</button>
                </form>
            @else
                <form method="POST" action="{{ route('billing.customers.suspend', $organization->id) }}" style="margin:0"
                      data-confirm="Suspend {{ $c['org'] }}? Access is held (billing and credits are untouched) until you reactivate."
                      data-confirm-title="Suspend customer?" data-confirm-label="Suspend" data-confirm-variant="destructive">
                    @csrf<button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Suspend</button>
                </form>
            @endif
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
            <header class="cbx-panel-header" style="padding:12px 20px">
                <h2 class="cbx-panel-title" style="font-size:14px">Subscription</h2>
                @if ($c['subscription_id'] !== null)
                    <a class="cbx-link" style="font-size:12px" href="{{ route('billing.subscriptions.show', $c['subscription_id']) }}">Open →</a>
                @endif
            </header>
            <dl style="margin:0;padding:2px 20px 6px">
                <div class="cbx-kv" style="padding:9px 0"><dt>Plan</dt><dd>@if ($c['subscription_id'] !== null)<a class="cbx-link" href="{{ route('billing.subscriptions.show', $c['subscription_id']) }}">{{ $c['plan'] ?? '—' }}</a>@else{{ $c['plan'] ?? 'No active subscription' }}@endif</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Status</dt><dd><span class="cbx-pill cbx-pill--{{ $statusPill[$c['status']] ?? 'muted' }}">{{ $c['status'] === 'none' ? 'no sub' : $c['status'] }}</span></dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>MRR</dt><dd class="num">{{ MoneyFormatter::minor($c['mrr'], $c['currency']) }}</dd></div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Outstanding</dt><dd class="num">{{ $c['outstanding_label'] }}</dd></div>
            </dl>
        </section>
    </div>

    {{-- Edit the org profile. Currency is one-way locked once the account transacts. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Edit profile</h2></header>
        <form method="POST" action="{{ route('billing.customers.update', $organization->id) }}" class="cbx-grid-2" style="gap:12px;padding:8px 20px 18px;align-items:end">
            @csrf @method('PUT')
            <label style="{{ $labelStyle }}">Organization name
                <input name="name" value="{{ old('name', $c['org']) }}" required maxlength="190" style="{{ $inputStyle }}"></label>
            <label style="{{ $labelStyle }}">Billing email
                <input type="email" name="billing_email" value="{{ old('billing_email', $c['billing_email']) }}" maxlength="190" style="{{ $inputStyle }}"></label>
            <label style="{{ $labelStyle }}">Tax ID / VAT number
                <input name="tax_id" value="{{ old('tax_id', $c['tax_id']) }}" maxlength="64" style="{{ $inputStyle }}"></label>
            <label style="{{ $labelStyle }}">Billing currency
                <input name="billing_currency" value="{{ old('billing_currency', $c['currency']) }}" maxlength="3" pattern="[A-Za-z]{3}" style="{{ $inputStyle }};text-transform:uppercase{{ !empty($c['currency_locked']) ? ';opacity:.55' : '' }}" {{ !empty($c['currency_locked']) ? 'readonly' : '' }}>
                @if (!empty($c['currency_locked']))<span class="mut" style="font-size:11px">Locked — the account has transacted in {{ $c['currency'] }}.</span>@else<span class="mut" style="font-size:11px">Fixed once the first invoice finalizes.</span>@endif
            </label>
            <div style="grid-column:1 / -1"><button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'check', 'size' => 13, 'sw' => 1.8])Save profile</button></div>
        </form>
    </section>

    {{-- Vaulted payment methods (gateway-owned; only display fields ever surface). --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <h2 class="cbx-panel-title" style="font-size:14px">Payment methods</h2>
            <span class="num mut" style="font-size:11px">{{ $payment['gateway'] }}@if($payment['gateway_customer_id']) · {{ $payment['gateway_customer_id'] }}@endif</span>
        </header>
        @if ($payment['manual'])
            <div style="padding:14px 20px" class="mut">The <strong>{{ $payment['gateway'] }}</strong> gateway settles out of band and vaults no cards — there are no stored methods to manage.</div>
        @elseif (empty($payment['methods']))
            <div style="padding:14px 20px" class="mut">No vaulted payment methods@if(!$payment['gateway_customer_id']) — this org has no gateway customer yet@endif.</div>
        @else
            <table class="tbl">
                <thead><tr><th>Brand</th><th>Number</th><th>Expiry</th><th style="width:90px">Default</th><th style="width:180px"></th></tr></thead>
                <tbody>
                    @foreach ($payment['methods'] as $method)
                        <tr style="cursor:default">
                            <td style="font-weight:500">{{ ucfirst($method['brand']) }}</td>
                            <td class="num mut">···· {{ $method['last4'] }}</td>
                            <td class="num mut">{{ $method['exp'] ?? '—' }}</td>
                            <td>@if($method['default'])<span class="cbx-pill cbx-pill--info">default</span>@endif</td>
                            <td>
                                <div style="display:flex;gap:6px;justify-content:flex-end">
                                    @unless ($method['default'])
                                        <form method="POST" action="{{ route('billing.customers.payment-methods.default', $organization->id) }}" style="margin:0">
                                            @csrf<input type="hidden" name="id" value="{{ $method['id'] }}"><button type="submit" class="cbx-btn cbx-btn--sm">Make default</button>
                                        </form>
                                    @endunless
                                    <form method="POST" action="{{ route('billing.customers.payment-methods.remove', $organization->id) }}" style="margin:0"
                                          data-confirm="Remove the {{ ucfirst($method['brand']) }} ···· {{ $method['last4'] }} card? It is detached from the gateway vault."
                                          data-confirm-title="Remove payment method?" data-confirm-label="Remove" data-confirm-variant="destructive">
                                        @csrf<input type="hidden" name="id" value="{{ $method['id'] }}"><button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

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
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'invoice', 'size' => 18, 'sw' => 1.7])</div><h3>No invoices yet.</h3><p>Invoices issued to this customer will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Coupons this customer has redeemed — cross-linked to the coupon + the subscription. --}}
    @if (count($redemptions) > 0)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Coupons redeemed</h2><span class="mut num" style="font-size:12px">{{ count($redemptions) }}</span></header>
            <table class="tbl">
                <thead><tr><th>Coupon</th><th style="width:150px">Subscription</th><th style="width:150px">Redeemed</th></tr></thead>
                <tbody>
                    @foreach ($redemptions as $redemption)
                        <tr>
                            <td><a class="cbx-link" href="{{ route('billing.coupons.show', $redemption['coupon_id']) }}"><span class="num">{{ $redemption['code'] }}</span>@if ($redemption['name'] !== '—') <span class="mut">· {{ $redemption['name'] }}</span>@endif</a></td>
                            <td class="num">@if ($redemption['subscription_id'] !== null)<a class="cbx-link" href="{{ route('billing.subscriptions.show', $redemption['subscription_id']) }}">#{{ $redemption['subscription_id'] }}</a>@else<span class="mut">—</span>@endif</td>
                            <td class="num mut">{{ $redemption['redeemed_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

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

    {{-- Access & roles — the RBAC mirror the provisioning webhooks maintain (read-only). --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
            <div>
                <h2 class="cbx-panel-title" style="font-size:14px">Access &amp; roles</h2>
                <p class="cbx-panel-desc" style="font-size:12px">A projection of the Cbox ID access mirror — Cbox ID owns assignment.</p>
            </div>
            <a href="{{ route('billing.access-grants', ['q' => $organization->id]) }}" class="cbx-btn cbx-btn--sm">All grants</a>
        </header>
        <table class="tbl">
            <thead><tr><th>Subject</th><th>Role</th><th style="width:120px">Kind</th><th style="width:120px">Environment</th><th style="width:150px">Updated</th></tr></thead>
            <tbody>
                @forelse ($accessGrants as $grant)
                    <tr style="cursor:default">
                        <td class="num">{{ $grant['subject'] }}</td>
                        <td>{{ $grant['role'] ?? '—' }}</td>
                        <td><span class="cbx-pill cbx-pill--{{ $grant['kind'] === 'role' ? 'info' : 'muted' }}">{{ $grant['kind'] }}</span></td>
                        <td class="num mut">{{ $grant['environment'] ?? '—' }}</td>
                        <td class="num mut">{{ $grant['updated'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>No access grants yet.</h3><p>Grants appear as Cbox ID provisioning webhooks (member / role events) arrive.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Activity log — the account's real records aggregated + cross-linked. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Activity log</h2></header>
        <table class="tbl">
            <thead><tr><th style="width:150px">When</th><th style="width:120px">Type</th><th>Event</th><th style="width:40px"></th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    @php $href = $event['href']; @endphp
                    <tr @if($href) data-href="{{ $href }}" tabindex="0" role="link" aria-label="Open {{ $event['label'] }}" @else style="cursor:default" @endif>
                        <td class="num mut">{{ $event['at'] }}</td>
                        <td><span class="cbx-pill cbx-pill--muted">{{ $event['type'] }}</span></td>
                        <td>{{ $event['label'] }}@if($event['detail'])<span class="mut" style="font-size:11px;margin-left:6px">{{ $event['detail'] }}</span>@endif</td>
                        <td class="rowchev">@if($href)@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7])@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'activity', 'size' => 18, 'sw' => 1.7])</div><h3>No recorded activity yet.</h3><p>Billing events for this customer will appear here.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
