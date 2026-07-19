@extends('layouts.app')
@section('title', 'Webhooks')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Webhooks'],
    ]" />
@endsection

@php
    // Never read from a flashed session value — the show-once secret is rendered directly into the
    // POST response (SEC-3), so a persistent session driver never writes it to disk.
    $revealed = $revealed ?? null;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Webhooks</h1>
            <p class="cbx-page-desc" style="font-size:13px">Register endpoints to receive signed, outbound billing events. Each delivery is signed with the endpoint's secret over <span class="num">HMAC-SHA256</span> (header <span class="num">X-Cbox-Signature</span>) so you can verify authenticity — the signing scheme and event catalog are documented in the outbound-webhooks integration guide.</p>
        </div>
        <a href="{{ route('billing.settings.webhooks.create') }}" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])Register endpoint</a>
    </header>

    @include('partials.flash')

    {{-- A freshly minted / rotated signing secret — shown ONCE. --}}
    @if (is_array($revealed))
        <section class="cbx-panel" style="border-left:3px solid var(--success, #16a34a)">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <h2 class="cbx-panel-title" style="font-size:14px">{{ $revealed['label'] }} — copy the signing secret now</h2>
            </header>
            <div style="padding:6px 20px 16px">
                <p class="cbx-page-desc" style="font-size:12px;margin:0 0 8px">This is the only time the secret is shown. Store it in your integration's config; verify each delivery's <span class="num">X-Cbox-Signature</span> against it. If you lose it, rotate to mint a new one.</p>
                <textarea readonly rows="2" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);resize:vertical">{{ $revealed['secret'] }}</textarea>
            </div>
        </section>
    @endif

    @forelse ($endpoints as $endpoint)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="min-width:0">
                    <h2 class="cbx-panel-title num" style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $endpoint->url }}</h2>
                    @if ($endpoint->description)
                        <p class="mut" style="font-size:12px;margin:2px 0 0">{{ $endpoint->description }}</p>
                    @endif
                </div>
                @if ($endpoint->active)
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>active</span>
                @else
                    <span class="cbx-pill cbx-pill--muted">inactive</span>
                @endif
            </header>

            <dl style="margin:0;padding:6px 20px 12px">
                <div class="cbx-kv" style="padding:9px 0;align-items:flex-start">
                    <dt>Subscribed events</dt>
                    <dd>
                        @forelse ($endpoint->event_types as $type)
                            <span class="cbx-pill cbx-pill--muted num" style="font-size:11px;margin:0 4px 4px 0">{{ $type }}</span>
                        @empty
                            <span class="mut">none — this endpoint will receive nothing</span>
                        @endforelse
                    </dd>
                </div>
                <div class="cbx-kv" style="padding:9px 0"><dt>Deliveries</dt><dd class="num">{{ $endpoint->deliveries_count }}</dd></div>
            </dl>

            <div style="display:flex;gap:8px;flex-wrap:wrap;padding:0 20px 16px">
                <a href="{{ route('billing.settings.webhooks.show', $endpoint) }}" class="cbx-btn">Delivery log</a>
                <a href="{{ route('billing.settings.webhooks.edit', $endpoint) }}" class="cbx-btn">Edit</a>

                <form method="POST" action="{{ route('billing.settings.webhooks.test', $endpoint) }}">@csrf
                    <button type="submit" class="cbx-btn">Send test event</button>
                </form>

                <form method="POST" action="{{ route('billing.settings.webhooks.rotate', $endpoint) }}"
                      data-confirm="Rotate the signing secret? The current secret stops verifying immediately — update your integration in the same window." data-confirm-title="Rotate secret?" data-confirm-label="Rotate" data-confirm-variant="primary">@csrf
                    <button type="submit" class="cbx-btn">Rotate secret</button>
                </form>

                @if ($endpoint->active)
                    <form method="POST" action="{{ route('billing.settings.webhooks.deactivate', $endpoint) }}">@csrf
                        <button type="submit" class="cbx-btn">Deactivate</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('billing.settings.webhooks.activate', $endpoint) }}">@csrf
                        <button type="submit" class="cbx-btn">Activate</button>
                    </form>
                @endif

                <form method="POST" action="{{ route('billing.settings.webhooks.destroy', $endpoint) }}"
                      data-confirm="Delete this endpoint and its delivery history? This cannot be undone." data-confirm-title="Delete endpoint?" data-confirm-label="Delete" data-confirm-variant="destructive">@csrf @method('DELETE')
                    <button type="submit" class="cbx-btn" style="color:var(--destructive)">Delete</button>
                </form>
            </div>
        </section>
    @empty
        <section class="cbx-panel">
            <div class="cbx-empty">
                <div class="cbx-empty-icon">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 18, 'sw' => 1.7])</div>
                <h3>No webhook endpoints yet.</h3>
                <p>Register one to start receiving signed billing events, then watch its delivery log here.</p>
                <a href="{{ route('billing.settings.webhooks.create') }}" class="cbx-btn cbx-btn--primary" style="margin-top:12px">Register endpoint</a>
            </div>
        </section>
    @endforelse
</div>
@endsection
