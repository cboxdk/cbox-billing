{{--
    Soft "upgrade to unlock" gate (ADR-0009/#52). Renders the CTA an enforcement denial
    carries so the console/portal can bridge a refusal to the checkout, rather than a dead end.

    INTERNAL CONSOLE / PORTAL ONLY. Styled with the shared `cbx-*` design tokens from
    `public/cbox/styles.css`, so it only renders correctly on a surface that already loads that
    stylesheet (the operator console + hosted portal) — it is not self-contained. To surface an
    upgrade offer inside YOUR OWN app, redirect to the self-contained hosted paywall
    (route('storefront.paywall') → GET /paywall) or the hosted pricing table
    (route('storefront.show', key) → GET /pricing/{key}).

        @include('partials.upgrade-gate', [
            'upgrade' => ['required_plan' => 'team', 'checkout_url' => '/billing/checkout/…'],
            'feature' => 'Ingested events',   // optional label of what is locked
        ])

    Deny-by-default: given no upgrade (null/empty), the partial renders nothing.
--}}
@php
    $upgrade = $upgrade ?? null;
    $feature = $feature ?? null;
    $requiredPlan = is_array($upgrade) ? ($upgrade['required_plan'] ?? null) : null;
    $checkoutUrl = is_array($upgrade) ? ($upgrade['checkout_url'] ?? null) : null;
@endphp

@if ($requiredPlan)
    <div class="cbx-upgrade" role="note" style="display:flex;align-items:center;gap:12px;justify-content:space-between;padding:12px 16px;border:1px solid var(--border);border-radius:10px;background:var(--accent-soft)">
        <div style="display:flex;align-items:center;gap:10px">
            <span class="avatar-sm" style="width:24px;height:24px;font-size:11px;background:var(--accent-soft);color:var(--primary)">@include('partials.icon', ['name' => 'box', 'size' => 14, 'sw' => 1.7])</span>
            <div>
                <span style="display:block;font-size:13px;font-weight:600">
                    @if ($feature) {{ $feature }} is not in your plan @else Upgrade to unlock @endif
                </span>
                <span class="mut" style="font-size:12px">Available on the {{ ucfirst($requiredPlan) }} plan.</span>
            </div>
        </div>
        @if ($checkoutUrl)
            <a class="cbx-btn cbx-btn--primary cbx-btn--sm" href="{{ $checkoutUrl }}">Upgrade to {{ ucfirst($requiredPlan) }}</a>
        @else
            <span class="cbx-pill cbx-pill--muted">{{ ucfirst($requiredPlan) }} plan</span>
        @endif
    </div>
@endif
