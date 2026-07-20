{{--
    Drop-in paywall PANEL (#57) — the richer sibling of `partials.upgrade-gate`, for an integrator
    who already holds the UpgradeGate's output and wants to render the full "upgrade to unlock"
    card inline (in the console, the portal, or their own app) rather than redirecting to the
    hosted `/paywall` page. It RE-USES the gate output verbatim — it never recomputes the upgrade
    logic — and renders on the shared `cbx-*` design tokens.

        @include('partials.paywall', [
            'upgrade' => ['required_plan' => 'team', 'checkout_url' => '/billing/checkout/…'], // UpgradeGate output
            'feature' => 'Single sign-on',   // label of the gated capability
            'kind'    => 'feature',          // 'feature' | 'usage' (optional, default 'feature')
            'price'   => 'DKK 1.240,00',     // optional formatted price of the required plan
            'per'     => '/mo',              // optional interval suffix
            'returnUrl' => '/back',          // optional "maybe later" target
        ])

    Deny-by-default: given no upgrade (null/empty), the partial renders nothing.
--}}
@php
    $upgrade = $upgrade ?? null;
    $feature = $feature ?? 'this feature';
    $kind = ($kind ?? 'feature') === 'usage' ? 'usage limit' : 'feature';
    $price = $price ?? null;
    $per = $per ?? null;
    $returnUrl = $returnUrl ?? null;
    $requiredPlan = is_array($upgrade) ? ($upgrade['required_plan'] ?? null) : null;
    $checkoutUrl = is_array($upgrade) ? ($upgrade['checkout_url'] ?? null) : null;
@endphp

@if ($requiredPlan)
    <div class="cbx-paywall" role="dialog" aria-label="Upgrade required"
         style="max-width:420px;border:1px solid var(--border);border-radius:16px;background:var(--card);box-shadow:var(--shadow-card);overflow:hidden">
        <div style="padding:22px 22px 0;text-align:center">
            <span style="display:inline-flex;width:42px;height:42px;border-radius:11px;align-items:center;justify-content:center;background:var(--accent-soft);color:var(--primary);margin-bottom:12px">
                @include('partials.icon', ['name' => 'key', 'size' => 20, 'sw' => 1.8])
            </span>
            <div style="text-transform:uppercase;letter-spacing:.06em;font-size:11px;font-weight:700;color:var(--primary);margin-bottom:4px">Upgrade required</div>
            <h3 style="font-size:19px;font-weight:750;margin:0 0 6px">Unlock {{ $feature }}</h3>
            <p class="mut" style="font-size:13px;margin:0">{{ $feature }} is a {{ $kind }} that isn’t part of your current plan.</p>
        </div>
        <div style="padding:18px 22px 22px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--secondary);border:1px solid var(--border);border-radius:12px;padding:13px 15px;margin-bottom:16px">
                <div style="font-weight:700;font-size:14px">{{ ucfirst($requiredPlan) }} plan
                    <span class="mut" style="display:block;font-weight:500;font-size:12px">Everything you have today, plus {{ $feature }}</span>
                </div>
                @if ($price)
                    <div style="font-size:18px;font-weight:800;white-space:nowrap;text-align:right">{{ $price }}<span class="mut" style="font-size:12px;font-weight:500">{{ $per }}</span></div>
                @endif
            </div>
            @if ($checkoutUrl)
                <a class="cbx-btn cbx-btn--primary" style="width:100%;justify-content:center" href="{{ $checkoutUrl }}">Upgrade to {{ ucfirst($requiredPlan) }}</a>
            @else
                <span class="cbx-pill cbx-pill--muted" style="display:block;text-align:center">{{ ucfirst($requiredPlan) }} plan</span>
            @endif
            @if ($returnUrl)
                <a href="{{ $returnUrl }}" class="mut" style="display:block;text-align:center;margin-top:12px;font-size:13px;text-decoration:none">Maybe later</a>
            @endif
        </div>
    </div>
@endif
