@extends('layouts.hosted')
@section('title', 'Manage subscription')

@php
    use App\Billing\Support\MoneyFormatter;
    $statusPill = ['paid' => 'success', 'open' => 'warning', 'draft' => 'muted', 'void' => 'muted'];
@endphp

@section('content')
<div class="hosted-card">
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

@if ($subscription && count($plans) > 0)
<div class="hosted-card">
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
<div class="hosted-card">
    <header><h1>Payment method</h1><p>Update the card future renewals are charged to.</p></header>
    <div class="hosted-body">
        <div id="methods">
            @forelse ($methods as $method)
                <div class="line"><span class="k">{{ ucfirst($method->brand) }} ···· {{ $method->last4 ?: '—' }}</span><span class="v">@if($method->isDefault)<span class="cbx-pill cbx-pill--info">default</span>@endif</span></div>
            @empty
                <div class="line"><span class="k">No saved payment method.</span></div>
            @endforelse
        </div>
        <button class="cbx-btn cbx-btn--secondary" id="update-pm" style="margin-top:12px">Update payment method</button>
        <div id="pm-section" style="display:none;margin-top:14px">
            <div class="element" id="setup-element"></div>
            <button class="cbx-btn cbx-btn--primary" id="save-pm" style="margin-top:12px" disabled>Save card</button>
        </div>
        <div class="alert" id="pm-error"><span></span></div>
    </div>
</div>
@endif

<div class="hosted-card">
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

@if ($subscription && ! $subscription->cancel_at_period_end)
<div class="hosted-card">
    <div class="hosted-body">
        <button class="cbx-btn cbx-btn--ghost" id="cancel-btn" style="color:var(--destructive)">Cancel subscription at period end</button>
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
    function money(minor, cur) { return cur + ' ' + (minor / 100).toLocaleString('da-DK', { minimumFractionDigits: 2 }); }
    function loadScript(src) { return new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('load failed')); document.head.appendChild(s); }); }

    // --- Change plan: preview then confirm ---
    let selectedPlan = null;
    document.querySelectorAll('[data-preview]').forEach(btn => btn.addEventListener('click', async () => {
        document.getElementById('change-error').classList.remove('show');
        selectedPlan = btn.dataset.plan;
        const { ok, body } = await post('/preview', { plan: selectedPlan });
        if (!ok) { showErr('change-error', body.error); return; }
        document.getElementById('preview-text').innerHTML =
            'Due now: <strong>' + money(body.due_now_minor, body.currency) + '</strong><br>' +
            'New recurring: <strong>' + money(body.new_recurring_minor, body.currency) + '</strong><br>' +
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

    // --- Cancel ---
    const cancelBtn = document.getElementById('cancel-btn');
    if (cancelBtn) cancelBtn.addEventListener('click', async () => {
        cancelBtn.disabled = true;
        const { ok, body } = await post('/cancel', { at_period_end: true });
        if (!ok) { showErr('cancel-error', body.error); cancelBtn.disabled = false; return; }
        window.location.reload();
    });

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
