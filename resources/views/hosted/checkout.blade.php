@extends('layouts.hosted')
@section('title', 'Checkout')

@section('content')
<div class="hosted-card">
    <header>
        <h1>Subscribe to {{ $plan->name }}</h1>
        <p>Complete your payment to activate your subscription.</p>
    </header>
    <div class="hosted-body">
        <div class="line">
            <span class="k">{{ $plan->name }} · {{ ucfirst($plan->interval) }}</span>
            <span class="v num">{{ $price }}</span>
        </div>
        <div class="line total">
            <span class="k">Due today</span>
            <span class="v num" id="amount">{{ $price }}</span>
        </div>

        <div id="pay-section" style="margin-top:18px">
            <div class="element" id="payment-element">
                <div class="state show"><span class="spin"></span> Preparing secure payment…</div>
            </div>
            <button class="cbx-btn cbx-btn--primary" id="pay-btn" style="margin-top:14px" disabled>Pay {{ $price }}</button>
        </div>

        <div class="alert" id="error" role="alert"><span id="error-text"></span></div>

        <div class="state" id="processing">
            <span class="spin"></span> Payment received — activating your subscription…
        </div>

        <div class="note" id="offline-note" style="display:none">
            This account settles by bank transfer. Your subscription activates once payment is
            confirmed; you can close this window.
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const token = @json($session->token);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = @json(url('/billing/checkout')) + '/' + token;

    const els = {
        element: document.getElementById('payment-element'),
        payBtn: document.getElementById('pay-btn'),
        paySection: document.getElementById('pay-section'),
        error: document.getElementById('error'),
        errorText: document.getElementById('error-text'),
        processing: document.getElementById('processing'),
        offline: document.getElementById('offline-note'),
    };

    function fail(message) {
        els.errorText.textContent = message || 'Something went wrong. Please try again.';
        els.error.classList.add('show');
        els.payBtn.disabled = false;
        els.payBtn.textContent = els.payBtn.dataset.label || 'Pay';
    }

    function post(path) {
        return fetch(base + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        }).then(r => r.json().then(body => ({ ok: r.ok, body })));
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('Failed to load ' + src));
            document.head.appendChild(s);
        });
    }

    // After the gateway confirms client-side, the subscription is NOT yet active — it is
    // activated by the gateway's settled webhook. Poll the session until it flips complete,
    // then return to the merchant.
    function awaitActivation() {
        els.paySection.style.display = 'none';
        els.processing.classList.add('show');
        const poll = () => fetch(base + '/status', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(s => {
                if (s.complete) { window.location = s.return_url; return; }
                setTimeout(poll, 2000);
            })
            .catch(() => setTimeout(poll, 3000));
        poll();
    }

    async function mountStripe(intent) {
        if (!intent.publishable_key || !intent.client_secret) { throw new Error('Missing gateway keys.'); }
        await loadScript('https://js.stripe.com/v3/');
        const stripe = Stripe(intent.publishable_key);
        const elements = stripe.elements({ clientSecret: intent.client_secret });
        const paymentElement = elements.create('payment');
        els.element.innerHTML = '';
        paymentElement.mount('#payment-element');

        els.payBtn.disabled = false;
        els.payBtn.dataset.label = els.payBtn.textContent;
        els.payBtn.addEventListener('click', async () => {
            els.error.classList.remove('show');
            els.payBtn.disabled = true;
            els.payBtn.textContent = 'Processing…';
            // The element completes any SCA / 3-D Secure challenge itself; redirect only if
            // the method demands it, otherwise we stay and poll for webhook activation.
            const { error, paymentIntent } = await stripe.confirmPayment({
                elements,
                confirmParams: { return_url: window.location.href },
                redirect: 'if_required',
            });
            if (error) { fail(error.message); return; }
            if (paymentIntent && (paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing')) {
                awaitActivation();
            } else {
                fail('Payment could not be completed.');
            }
        });
    }

    post('/intent').then(async ({ ok, body }) => {
        if (!ok) { fail(body.error); return; }
        // A gateway with no client-side element (e.g. the manual/bank-transfer gateway)
        // settles out of band — there is nothing to mount; show the honest offline note.
        if (!body.client_secret || !body.publishable_key) {
            els.paySection.style.display = 'none';
            els.offline.style.display = 'block';
            awaitActivation();
            return;
        }
        try {
            if (body.gateway === 'stripe') { await mountStripe(body); }
            else { throw new Error('This payment gateway is not available for embedded checkout.'); }
        } catch (e) { fail(e.message); }
    }).catch(() => fail());
})();
</script>
@endpush
@endsection
