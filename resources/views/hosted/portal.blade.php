@extends('layouts.hosted')
@section('title', 'Manage subscription')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted', 'void' => 'muted'];
@endphp

@push('head')
<style>
    .portal-nav { display: flex; gap: 4px; flex-wrap: wrap; position: sticky; top: 0; z-index: 5; margin: -4px 0 0; padding: 8px; background: color-mix(in srgb, var(--background) 88%, transparent); backdrop-filter: blur(8px); border: 1px solid var(--border); border-radius: var(--radius); }
    .portal-nav a { font-size: 12.5px; color: var(--muted-foreground); text-decoration: none; padding: 6px 10px; border-radius: var(--radius-md); white-space: nowrap; }
    .portal-nav a:hover { background: var(--secondary); color: var(--foreground); }
    .meter + .meter { margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
    .meter-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
    .meter-name { font-size: 13.5px; font-weight: 600; }
    .meter-figs { font-size: 12.5px; color: var(--muted-foreground); }
    .meter-bar { margin: 8px 0 6px; height: 8px; border-radius: 9999px; background: var(--secondary); overflow: hidden; }
    .meter-fill { display: block; height: 100%; border-radius: 9999px; background: var(--primary); transition: width .3s ease; }
    .meter-fill--warn { background: var(--warning); }
    .meter-fill--over { background: var(--destructive); }
    .meter-fill--unlimited { background: var(--info, var(--primary)); }
    .meter-foot { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11.5px; color: var(--muted-foreground); }
    .meter-foot .meter-over { color: var(--destructive); font-weight: 600; }
    .meter-foot .meter-proj { color: var(--warning); }
    .cbx-switch { position: relative; display: inline-flex; flex: 0 0 auto; width: 40px; height: 24px; cursor: pointer; }
    .cbx-switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
    .cbx-switch-track { flex: 1; border-radius: 9999px; background: var(--muted, var(--secondary)); border: 1px solid var(--border); transition: background .2s ease; }
    .cbx-switch-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 9999px; background: #fff; box-shadow: var(--shadow-card); transition: transform .2s ease; }
    .cbx-switch input:checked + .cbx-switch-track { background: var(--primary); border-color: var(--primary); }
    .cbx-switch input:checked + .cbx-switch-track::after { transform: translateX(16px); }
    .cbx-switch input:disabled { cursor: not-allowed; }
    .notif-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 0; }
    .notif-row + .notif-row { border-top: 1px solid var(--border); }
    .notif-row .t { font-size: 13.5px; font-weight: 600; }
    .notif-row .d { font-size: 12px; color: var(--muted-foreground); margin-top: 2px; }
    .seat-input { width: 96px; height: 36px; border: 1px solid var(--border); border-radius: var(--radius-md); background: var(--card); color: var(--foreground); padding: 0 10px; font-family: var(--font-mono, monospace); }
</style>
@endpush

@section('content')
<nav class="portal-nav" aria-label="Portal sections">
    <a href="#overview">Overview</a>
    @if ($usage)<a href="#usage">Usage</a>@endif
    @if ($subscription && $seats)<a href="#seats">Seats</a>@endif
    @if ($subscription && count($plans) > 0)<a href="#plan">Plan</a>@endif
    @if ($subscription)<a href="#payment">Payment</a>@endif
    <a href="#history">Billing history</a>
    <a href="#notifications">Notifications</a>
    @if ($subscription && ! $subscription->cancel_at_period_end)<a href="#cancel">Cancel</a>@endif
</nav>

<div class="hosted-card" id="overview">
    <header>
        <h1>Manage your subscription</h1>
        <p>{{ $organization->name }}</p>
    </header>
    <div class="hosted-body">
        {{-- Current subscription --}}
        @if ($subscription)
            <div class="line">
                <span class="k">Current plan</span>
                <span class="v">{{ $subscription->plan?->name ?? '—' }}</span>
            </div>
            <div class="line">
                <span class="k">Status</span>
                <span class="v">
                    <span class="cbx-pill cbx-pill--{{ $subscription->standing() === 'active' ? 'success' : 'muted' }}"><span class="dot"></span>{{ $subscription->standing() }}</span>
                </span>
            </div>
            <div class="line">
                <span class="k">{{ $subscription->cancel_at_period_end ? 'Ends on' : 'Renews on' }}</span>
                <span class="v num">{{ $subscription->current_period_end?->format('Y-m-d') ?? '—' }}</span>
            </div>
        @else
            <div class="note" style="margin-top:0">This organization has no active subscription.</div>
        @endif
    </div>
</div>

{{-- Usage & consumption for the current period (metered plans only) --}}
@if ($usage)
<div class="hosted-card" id="usage">
    <header>
        <h1>Usage this period</h1>
        <p>{{ $usage['period_start'] }} – {{ $usage['period_end'] }} · resets {{ $usage['period_end'] }}</p>
    </header>
    <div class="hosted-body">
        @foreach ($usage['meters'] as $m)
            <div class="meter">
                <div class="meter-head">
                    <span class="meter-name">{{ $m['name'] }}</span>
                    <span class="meter-figs num">
                        @if ($m['unlimited'])
                            {{ number_format($m['used']) }} {{ $m['unit'] }} · unlimited
                        @else
                            {{ number_format($m['used']) }} / {{ number_format($m['allowance']) }} {{ $m['unit'] }}
                        @endif
                    </span>
                </div>
                <div class="meter-bar"><span class="meter-fill meter-fill--{{ $m['state'] }}" style="width:{{ max(2, $m['percent']) }}%"></span></div>
                <div class="meter-foot">
                    @if ($m['unlimited'])
                        <span>Unlimited allowance</span>
                    @else
                        <span>{{ $m['percent'] }}% used</span>
                        <span>{{ number_format(max(0, $m['allowance'] - $m['used'])) }} {{ $m['unit'] }} remaining</span>
                    @endif
                    @if ($m['overage'] > 0)
                        <span class="meter-over">{{ number_format($m['overage']) }} {{ $m['unit'] }} over</span>
                    @elseif (! $m['unlimited'] && $m['projected_overage'] > 0)
                        <span class="meter-proj">projected {{ number_format($m['projected_overage']) }} {{ $m['unit'] }} over by {{ $usage['period_end'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- Self-serve seats: buy/release + assign to members --}}
@if ($subscription && $seats)
<div class="hosted-card" id="seats">
    <header><h1>Seats</h1><p>Buy or release seats, and assign them to your team members.</p></header>
    <div class="hosted-body">
        <div class="line"><span class="k">Purchased seats</span><span class="v num" id="seat-purchased">{{ $seats->purchased }}</span></div>
        <div class="line"><span class="k">Assigned (Full)</span><span class="v num">{{ $seats->assigned }}</span></div>
        <div class="line"><span class="k">Free to assign</span><span class="v num">{{ $seats->free() }}</span></div>

        {{-- Buy / release: preview the prorated due-now, then confirm --}}
        <div class="line" style="flex-direction:column;align-items:stretch;gap:8px">
            <span class="k">Change purchased seats</span>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="number" min="1" step="1" id="seat-count" value="{{ $seats->purchased }}" class="seat-input" aria-label="Number of seats">
                <button class="cbx-btn cbx-btn--secondary cbx-btn--sm" id="seat-preview" style="width:auto">Preview</button>
            </div>
            <div class="note" id="seat-preview-box" style="display:none">
                <div id="seat-preview-text"></div>
                <button class="cbx-btn cbx-btn--primary cbx-btn--sm" id="seat-confirm" style="width:auto;margin-top:10px">Confirm change</button>
            </div>
        </div>

        {{-- Assign / unassign the org's own members --}}
        <div style="margin-top:6px">
            <span class="k">Team members</span>
            <div id="seat-members" style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
                @forelse (array_merge($seats->full, $seats->assignable) as $member)
                    @php $isFull = in_array($member, $seats->full, true); @endphp
                    <div class="line" style="border:1px solid var(--border);border-radius:var(--radius-md);padding:10px 12px">
                        <span class="k">{{ $member['subject'] }} <span class="cbx-pill cbx-pill--{{ $isFull ? 'info' : 'muted' }}">{{ $isFull ? 'Full' : 'Light' }}</span></span>
                        @if ($isFull)
                            <button class="cbx-btn cbx-btn--ghost cbx-btn--sm" style="width:auto" data-seat-unassign="{{ $member['subject'] }}">Unassign</button>
                        @else
                            <button class="cbx-btn cbx-btn--secondary cbx-btn--sm" style="width:auto" data-seat-assign="{{ $member['subject'] }}">Assign seat</button>
                        @endif
                    </div>
                @empty
                    <div class="note" style="margin-top:0">No team members are synced for this organization yet.</div>
                @endforelse
            </div>
        </div>
        <div class="alert" id="seat-error"><span></span></div>
    </div>
</div>
@endif

@if ($subscription && $sunset)
<div class="hosted-card" style="border-left:3px solid {{ $sunset->unresolved ? 'var(--destructive)' : 'var(--warning)' }}">
    <header>
        <h1>{{ $sunset->planName }} is being retired</h1>
        <p>{{ $sunset->planName }} retires on {{ $sunset->retiresAt }}. Your next renewal is {{ $sunset->renewalDue }} — choose your new plan by then.</p>
    </header>
    <div class="hosted-body">
        @if ($sunset->unresolved)
            <div class="alert show" style="margin-top:0"><span>Your plan has retired and no new plan is chosen. Please pick a plan below to keep your subscription — it can't renew on the retired plan.</span></div>
        @endif

        {{-- Choice 1: pick a successor plan (schedules the change at renewal) --}}
        @if (count($sunset->successors) > 0)
            <div class="line" style="flex-direction:column;align-items:stretch;gap:8px">
                <span class="k">1 · Pick a new plan</span>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <select id="sunset-plan" style="flex:1;min-width:200px;height:34px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 10px;font-family:var(--font-sans)">
                        @foreach ($sunset->successors as $successor)
                            <option value="{{ $successor['key'] }}">{{ $successor['name'] }} · {{ $successor['price'] }}</option>
                        @endforeach
                    </select>
                    <button class="cbx-btn cbx-btn--primary" id="sunset-choose" style="width:auto">Switch at renewal</button>
                </div>
                @if ($sunset->election === 'successor')<span class="note" style="margin-top:0">You've chosen to move to <strong>{{ $sunset->electedSuccessorName }}</strong> at your next renewal.</span>@endif
            </div>
        @endif

        {{-- Choice 2: cancel at period end --}}
        <div class="line"><span class="k">2 · Cancel instead</span><button class="cbx-btn cbx-btn--ghost" id="sunset-cancel" style="width:auto;color:var(--destructive)">Cancel at period end</button></div>

        {{-- Choice 3: do nothing — the default is stated explicitly --}}
        <div class="note" style="margin-top:6px">
            3 · Do nothing —
            @if ($sunset->hasDefault())
                you'll be moved to <strong>{{ $sunset->defaultSuccessorName }}</strong> automatically at your next renewal.
            @else
                your subscription can't renew on the retired plan, so please choose one of the options above before {{ $sunset->renewalDue }}.
            @endif
        </div>
        <div class="alert" id="sunset-error"><span></span></div>
    </div>
</div>
@endif

@if ($subscription && count($plans) > 0)
<div class="hosted-card" id="plan">
    <header><h1>Change plan</h1><p>Preview the prorated charge before you confirm.</p></header>
    <div class="hosted-body">
        <div id="plan-list" style="display:flex;flex-direction:column;gap:8px">
            @foreach ($plans as $plan)
                <div class="line" style="border:1px solid var(--border);border-radius:var(--radius-md);padding:12px 14px">
                    <span><span class="v">{{ $plan['name'] }}</span> · <span class="num">{{ $plan['price'] }}</span></span>
                    <button class="cbx-btn cbx-btn--secondary cbx-btn--sm" style="width:auto" data-plan="{{ $plan['key'] }}" data-preview>Preview</button>
                </div>
            @endforeach
        </div>
        <div class="note" id="preview-box" style="display:none">
            <div id="preview-text"></div>
            <button class="cbx-btn cbx-btn--primary cbx-btn--sm" id="confirm-change" style="width:auto;margin-top:10px">Confirm change</button>
        </div>
        <div class="alert" id="change-error"><span></span></div>
    </div>
</div>
@endif

@if ($subscription)
<div class="hosted-card" id="payment">
    <header><h1>Payment methods</h1><p>Manage the cards future renewals are charged to.</p></header>
    <div class="hosted-body">
        <div id="methods">
            @forelse ($methods as $method)
                <div class="line">
                    <span class="k">{{ ucfirst($method->brand) }} ···· {{ $method->last4 ?: '—' }}</span>
                    <span class="v" style="display:flex;gap:8px;align-items:center">
                        @if($method->isDefault)
                            <span class="cbx-pill cbx-pill--info">default</span>
                        @else
                            <button class="cbx-btn cbx-btn--ghost cbx-btn--sm" data-pm-default="{{ $method->id }}" style="width:auto">Make default</button>
                        @endif
                        <button class="cbx-btn cbx-btn--ghost cbx-btn--sm" style="width:auto;color:var(--destructive)" data-pm-remove="{{ $method->id }}" data-pm-label="{{ ucfirst($method->brand) }} ···· {{ $method->last4 ?: '' }}">Remove</button>
                    </span>
                </div>
            @empty
                <div class="line"><span class="k">No saved payment method.</span></div>
            @endforelse
        </div>
        <button class="cbx-btn cbx-btn--secondary" id="update-pm" style="margin-top:12px">Add payment method</button>
        <div id="pm-section" style="display:none;margin-top:14px">
            <div class="element" id="setup-element"></div>
            <button class="cbx-btn cbx-btn--primary" id="save-pm" style="margin-top:12px" disabled>Save card</button>
        </div>
        <div class="alert" id="pm-error"><span></span></div>
    </div>
</div>
@endif

{{-- Billing history: broader than invoices — invoices, payments, refunds/credits, coupons --}}
<div class="hosted-card" id="history">
    <header><h1>Billing history</h1><p>Invoices, payments, refunds, credits and promo codes.</p></header>
    <div class="hosted-body" style="padding:0">
        <table class="tbl">
            <thead><tr><th>Date</th><th>Activity</th><th class="right">Amount</th><th class="right">Status</th><th class="right"></th></tr></thead>
            <tbody>
                @forelse ($history as $event)
                    <tr>
                        <td class="num">{{ $event['at'] }}</td>
                        <td>{{ $event['title'] }}@if ($event['detail'])<br><span class="k">{{ $event['detail'] }}</span>@endif</td>
                        <td class="right num">{{ $event['amount'] ?? '—' }}</td>
                        <td class="right"><span class="cbx-pill cbx-pill--{{ $event['tone'] }}">{{ $event['status'] }}</span></td>
                        <td class="right">@if ($event['invoice_id'])<a href="{{ route('hosted.portal.invoice-pdf', ['token' => $session->token, 'invoice' => $event['invoice_id']]) }}">PDF</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:16px 20px;color:var(--muted-foreground)">No billing history yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="hosted-card" id="invoices">
    <header><h1>Invoices</h1></header>
    <div class="hosted-body" style="padding:0">
        <table class="tbl">
            <thead><tr><th>Invoice</th><th>Date</th><th class="right">Amount</th><th class="right">Status</th><th class="right">PDF</th></tr></thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td class="num">{{ $invoice->number }}</td>
                        <td class="num">{{ $invoice->issued_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="right num">{{ MoneyFormatter::minor($invoice->total_minor, $invoice->currency) }}</td>
                        <td class="right"><span class="cbx-pill cbx-pill--{{ $statusPill[$invoice->status] ?? 'muted' }}">{{ $invoice->status }}</span></td>
                        <td class="right"><a href="{{ route('hosted.portal.invoice-pdf', ['token' => $session->token, 'invoice' => $invoice->id]) }}">Download</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:16px 20px;color:var(--muted-foreground)">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Notification preferences: optional toggles + always-on mandatory list --}}
<div class="hosted-card" id="notifications">
    <header><h1>Email notifications</h1><p>Choose the optional emails you receive. Billing and legal emails are always sent.</p></header>
    <div class="hosted-body">
        @foreach ($notifications['optional'] as $opt)
            <div class="notif-row">
                <span>
                    <span class="t">{{ $opt['label'] }}</span>
                    <span class="d">{{ $opt['description'] }}</span>
                </span>
                <label class="cbx-switch">
                    <input type="checkbox" data-notif="{{ $opt['event'] }}" @checked($opt['opted_in'])>
                    <span class="cbx-switch-track"></span>
                </label>
            </div>
        @endforeach
        <div class="note" style="margin-top:14px">
            <strong>Always sent</strong> — these transactional and legal emails can't be turned off:
            @foreach ($notifications['mandatory'] as $mandatory)<span>{{ $mandatory['label'] }}@if (! $loop->last) · @endif</span>@endforeach
        </div>
        <div class="alert" id="notif-error"><span></span></div>
        <div class="state" id="notif-saved"><span>Preference saved.</span></div>
    </div>
</div>

@if ($subscription && ! $subscription->cancel_at_period_end)
<div class="hosted-card" id="cancel">
    <header><h1>Cancel subscription</h1><p>Tell us why you're leaving — it helps us improve.</p></header>
    <div class="hosted-body">
        {{-- Save-offers the bound retention seam presents before the cancel --}}
        @if (count($offers) > 0)
            <div class="note" style="margin-top:0">
                <strong>Before you go:</strong>
                @foreach ($offers as $offer)
                    <span>{{ $offer['label'] }}.</span>
                @endforeach
            </div>
        @endif

        {{-- Survey reasons the bound seam returns --}}
        @if (count($reasons) > 0)
            <label class="k" style="display:block;margin:12px 0 4px">Reason</label>
            <select id="cancel-reason" style="width:100%;height:34px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 10px;font-family:var(--font-sans)">
                <option value="">Select a reason…</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason['key'] }}">{{ $reason['label'] }}</option>
                @endforeach
            </select>
            <input id="cancel-comment" placeholder="Anything else? (optional)" style="width:100%;height:34px;margin-top:8px;border:1px solid var(--border);border-radius:var(--radius-md);background:var(--card);color:var(--foreground);padding:0 10px;font-family:var(--font-sans);font-size:13px">
        @endif

        <button class="cbx-btn cbx-btn--ghost" id="cancel-btn" style="color:var(--destructive);margin-top:12px">Cancel subscription at period end</button>
        <div class="alert" id="cancel-error"><span></span></div>
    </div>
</div>
@endif

@push('scripts')
<script>
(function () {
    const token = @json($session->token);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = @json(url('/billing/portal')) + '/' + token;

    function post(path, payload) {
        return fetch(base + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload || {}),
        }).then(r => r.json().then(body => ({ ok: r.ok, body })));
    }
    function showErr(el, msg) { const a = document.getElementById(el); a.querySelector('span').textContent = msg || 'Something went wrong.'; a.classList.add('show'); }
    function loadScript(src) { return new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('load failed')); document.head.appendChild(s); }); }

    // --- Change plan: preview then confirm ---
    let selectedPlan = null;
    document.querySelectorAll('[data-preview]').forEach(btn => btn.addEventListener('click', async () => {
        document.getElementById('change-error').classList.remove('show');
        selectedPlan = btn.dataset.plan;
        const { ok, body } = await post('/preview', { plan: selectedPlan });
        if (!ok) { showErr('change-error', body.error); return; }
        document.getElementById('preview-text').innerHTML =
            'Due now: <strong>' + body.due_now + '</strong><br>' +
            'New recurring: <strong>' + body.new_recurring + '</strong><br>' +
            'Effective: <strong>' + new Date(body.effective_at).toLocaleDateString() + '</strong>';
        document.getElementById('preview-box').style.display = 'block';
    }));
    const confirmBtn = document.getElementById('confirm-change');
    if (confirmBtn) confirmBtn.addEventListener('click', async () => {
        if (!selectedPlan) return;
        confirmBtn.disabled = true;
        const { ok, body } = await post('/change', { plan: selectedPlan });
        if (!ok) { showErr('change-error', body.error); confirmBtn.disabled = false; return; }
        window.location.reload();
    });

    // --- Cancel (carries the survey reason + comment through the retention seam) ---
    const cancelBtn = document.getElementById('cancel-btn');
    if (cancelBtn) cancelBtn.addEventListener('click', async () => {
        const confirmed = await window.cboxConfirm({
            title: 'Cancel subscription?',
            body: 'Your subscription stays active until the end of the current billing period, then cancels. You can resubscribe at any time.',
            confirmLabel: 'Cancel at period end',
        });
        if (!confirmed) return;
        cancelBtn.disabled = true;
        const reasonEl = document.getElementById('cancel-reason');
        const commentEl = document.getElementById('cancel-comment');
        const { ok, body } = await post('/cancel', {
            at_period_end: true,
            reason: reasonEl ? reasonEl.value : null,
            comment: commentEl ? commentEl.value : null,
        });
        if (!ok) { showErr('cancel-error', body.error); cancelBtn.disabled = false; return; }
        window.location.reload();
    });

    // --- Sunset: pick a successor (schedule at renewal) or cancel ---
    const sunsetChoose = document.getElementById('sunset-choose');
    if (sunsetChoose) sunsetChoose.addEventListener('click', async () => {
        sunsetChoose.disabled = true;
        const plan = document.getElementById('sunset-plan').value;
        const { ok, body } = await post('/retirement/successor', { plan });
        if (!ok) { showErr('sunset-error', body.error); sunsetChoose.disabled = false; return; }
        window.location.reload();
    });
    const sunsetCancel = document.getElementById('sunset-cancel');
    if (sunsetCancel) sunsetCancel.addEventListener('click', async () => {
        const confirmed = await window.cboxConfirm({
            title: 'Cancel at period end?',
            body: 'Instead of moving to a new plan, your subscription will cancel at the end of the current period.',
            confirmLabel: 'Cancel at period end',
        });
        if (!confirmed) return;
        sunsetCancel.disabled = true;
        const { ok, body } = await post('/cancel', { at_period_end: true });
        if (!ok) { showErr('sunset-error', body.error); sunsetCancel.disabled = false; return; }
        window.location.reload();
    });

    // --- Manage existing methods: set-default + remove (with the confirm modal) ---
    document.querySelectorAll('[data-pm-default]').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        const { ok, body } = await post('/payment-method/default', { payment_method: btn.dataset.pmDefault });
        if (!ok) { showErr('pm-error', body.error); btn.disabled = false; return; }
        window.location.reload();
    }));
    document.querySelectorAll('[data-pm-remove]').forEach(btn => btn.addEventListener('click', async () => {
        const confirmed = await window.cboxConfirm({
            title: 'Remove payment method?',
            body: 'The ' + (btn.dataset.pmLabel || 'card') + ' is detached from your account. Future renewals will need a card on file.',
            confirmLabel: 'Remove', variant: 'destructive',
        });
        if (!confirmed) return;
        btn.disabled = true;
        const { ok, body } = await post('/payment-method/remove', { payment_method: btn.dataset.pmRemove });
        if (!ok) { showErr('pm-error', body.error); btn.disabled = false; return; }
        window.location.reload();
    }));

    // --- Seats: preview the prorated buy/release, then confirm ---
    const seatPreviewBtn = document.getElementById('seat-preview');
    if (seatPreviewBtn) seatPreviewBtn.addEventListener('click', async () => {
        document.getElementById('seat-error').classList.remove('show');
        const seats = parseInt(document.getElementById('seat-count').value, 10);
        if (!Number.isInteger(seats) || seats < 1) { showErr('seat-error', 'Enter a seat count of at least 1.'); return; }
        seatPreviewBtn.disabled = true;
        const { ok, body } = await post('/seats/preview', { seats });
        seatPreviewBtn.disabled = false;
        if (!ok) { showErr('seat-error', body.error); return; }
        const text = body.is_credit
            ? 'Reducing to <strong>' + body.to_seats + ' seats</strong> credits <strong>' + body.charge + '</strong> to your account — nothing is due now.'
            : 'Change to <strong>' + body.to_seats + ' seats</strong> — due now: <strong>' + body.due_now + '</strong> (prorated for the rest of this period).';
        document.getElementById('seat-preview-text').innerHTML = text;
        document.getElementById('seat-preview-box').style.display = 'block';
        document.getElementById('seat-confirm').dataset.seats = String(seats);
    });
    const seatConfirmBtn = document.getElementById('seat-confirm');
    if (seatConfirmBtn) seatConfirmBtn.addEventListener('click', async () => {
        const seats = parseInt(seatConfirmBtn.dataset.seats || '0', 10);
        if (!seats) return;
        const confirmed = await window.cboxConfirm({
            title: 'Change purchased seats?',
            body: 'This updates your billed seat quantity now and charges (or credits) the prorated difference for the rest of this period.',
            confirmLabel: 'Confirm', variant: 'primary',
        });
        if (!confirmed) return;
        seatConfirmBtn.disabled = true;
        const { ok, body } = await post('/seats', { seats });
        if (!ok) { showErr('seat-error', body.error); seatConfirmBtn.disabled = false; return; }
        window.location.reload();
    });

    // --- Seats: assign / unassign a member (cap-enforced server-side) ---
    document.querySelectorAll('[data-seat-assign]').forEach(btn => btn.addEventListener('click', async () => {
        document.getElementById('seat-error').classList.remove('show');
        btn.disabled = true;
        const { ok, body } = await post('/seats/assign', { subject: btn.dataset.seatAssign });
        if (!ok) { showErr('seat-error', body.error); btn.disabled = false; return; }
        window.location.reload();
    }));
    document.querySelectorAll('[data-seat-unassign]').forEach(btn => btn.addEventListener('click', async () => {
        const confirmed = await window.cboxConfirm({
            title: 'Unassign this seat?',
            body: 'The member becomes a Light member (no Full seat). Your purchased seat count is unchanged, so you can reassign it.',
            confirmLabel: 'Unassign', variant: 'destructive',
        });
        if (!confirmed) return;
        btn.disabled = true;
        const { ok, body } = await post('/seats/unassign', { subject: btn.dataset.seatUnassign });
        if (!ok) { showErr('seat-error', body.error); btn.disabled = false; return; }
        window.location.reload();
    }));

    // --- Notification preferences: toggle an optional mail on/off ---
    document.querySelectorAll('[data-notif]').forEach(input => input.addEventListener('change', async () => {
        document.getElementById('notif-error').classList.remove('show');
        const saved = document.getElementById('notif-saved');
        saved.classList.remove('show');
        input.disabled = true;
        const { ok, body } = await post('/notifications', { event: input.dataset.notif, opted_in: input.checked });
        input.disabled = false;
        if (!ok) { input.checked = !input.checked; showErr('notif-error', body.error); return; }
        saved.classList.add('show');
        setTimeout(() => saved.classList.remove('show'), 1800);
    }));

    // --- Update payment method: SetupIntent + gateway element ---
    const updateBtn = document.getElementById('update-pm');
    if (updateBtn) updateBtn.addEventListener('click', async () => {
        updateBtn.disabled = true;
        const { ok, body } = await post('/setup-intent', {});
        if (!ok) { showErr('pm-error', body.error); updateBtn.disabled = false; return; }
        if (body.gateway !== 'stripe' || !body.publishable_key || !body.client_secret) {
            showErr('pm-error', 'This gateway does not support updating a card here.');
            return;
        }
        try {
            await loadScript('https://js.stripe.com/v3/');
            const stripe = Stripe(body.publishable_key);
            const elements = stripe.elements({ clientSecret: body.client_secret });
            const el = elements.create('payment');
            document.getElementById('setup-element').innerHTML = '';
            el.mount('#setup-element');
            document.getElementById('pm-section').style.display = 'block';
            const saveBtn = document.getElementById('save-pm');
            saveBtn.disabled = false;
            saveBtn.addEventListener('click', async () => {
                saveBtn.disabled = true;
                const { error, setupIntent } = await stripe.confirmSetup({
                    elements, confirmParams: { return_url: window.location.href }, redirect: 'if_required',
                });
                if (error) { showErr('pm-error', error.message); saveBtn.disabled = false; return; }
                const res = await post('/payment-method', { payment_method: setupIntent.payment_method });
                if (!res.ok) { showErr('pm-error', res.body.error); saveBtn.disabled = false; return; }
                window.location.reload();
            });
        } catch (e) { showErr('pm-error', e.message); }
    });
})();
</script>
@endpush
@endsection
