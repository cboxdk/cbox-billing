@extends('layouts.app')
@section('title', 'Environments')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Environments'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Environments</h1>
            <p class="cbx-page-desc" style="font-size:13px">Named billing planes. Production is the real, protected, live-gateway plane; sandboxes are isolated, disposable datasets on test gateway keys. Clone production's config into a sandbox to try changes, reset a sandbox to wipe its book, or destroy one entirely.</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- Environments list --}}
    <section class="cbx-panel" id="environments">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">Planes</h2>
        </header>
        <table class="tbl">
            <thead><tr><th>Key</th><th>Name</th><th>Type</th><th>Gateway keys</th><th>Active</th><th style="width:220px"></th></tr></thead>
            <tbody>
                @foreach ($environments as $environment)
                    <tr>
                        <td class="num">{{ $environment['key'] }}</td>
                        <td>{{ $environment['name'] }}</td>
                        <td>
                            @if ($environment['protected'])
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>{{ $environment['type'] }} · protected</span>
                            @else
                                <span class="cbx-pill cbx-pill--muted">{{ $environment['type'] }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="mut num" style="font-size:11px">{{ $environment['gateway_key_mode'] }}</span>
                            @if ($environment['has_gateway_keys'])
                                <span class="cbx-pill cbx-pill--success" style="margin-left:6px"><span class="dot"></span>set</span>
                            @else
                                <span class="cbx-pill cbx-pill--muted" style="margin-left:6px">env-var / fake</span>
                            @endif
                        </td>
                        <td>@if ($environment['key'] === $activeEnvironment)<span class="cbx-pill cbx-pill--success"><span class="dot"></span>active</span>@else<span class="mut">—</span>@endif</td>
                        <td>
                            <div style="display:flex;gap:6px;justify-content:flex-end">
                                @if ($environment['protected'])
                                    <span class="mut" style="font-size:11px;align-self:center">Production is never reset or destroyed.</span>
                                @else
                                    <form method="POST" action="{{ route('billing.environments.reset', $environment['key']) }}" style="margin:0"
                                          data-confirm="Reset “{{ $environment['key'] }}”? This wipes ALL of its transactional data (subscriptions, invoices, customers, ledger, …). Its config (plans, branding, gateway keys) survives. This cannot be undone."
                                          data-confirm-title="Reset sandbox?" data-confirm-label="Reset" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Reset</button></form>
                                    <form method="POST" action="{{ route('billing.environments.destroy', $environment['key']) }}" style="margin:0"
                                          data-confirm="Destroy “{{ $environment['key'] }}”? This permanently deletes the plane AND all of its data — config and transactional. This cannot be undone."
                                          data-confirm-title="Destroy sandbox?" data-confirm-label="Destroy" data-confirm-variant="destructive">@csrf @method('DELETE')<button type="submit" class="cbx-btn cbx-btn--sm cbx-btn--destructive">Destroy</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Create a sandbox --}}
    <section class="cbx-panel" id="new">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">New sandbox</h2>
        </header>
        <form method="POST" action="{{ route('billing.environments.store') }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Key
                    <input type="text" name="key" value="{{ old('key') }}" required maxlength="40" placeholder="acme-test" pattern="[a-z0-9][a-z0-9-]{1,39}" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">Lowercase letters, digits and hyphens. The stable id every scoped row carries.</span>
                </label>
                <label style="{{ $labelStyle }}">Name <span class="mut" style="font-weight:400">(optional)</span>
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="120" placeholder="Acme Test" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">A human label; defaults to the key.</span>
                </label>
            </div>
            <label style="{{ $labelStyle }}">Clone config from <span class="mut" style="font-weight:400">(optional)</span>
                <select name="clone_from" style="{{ $inputStyle }}">
                    <option value="">Empty — no config copied</option>
                    @foreach ($environments as $environment)
                        <option value="{{ $environment['key'] }}" @selected(old('clone_from') === $environment['key'])>{{ $environment['name'] }} ({{ $environment['key'] }})</option>
                    @endforeach
                </select>
                <span class="mut" style="font-size:11px">Deep-copies the source plane's catalog, branding, storefront and templates into the new sandbox — an isolated copy with an empty book and test gateway keys.</span>
            </label>
            <div>
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])Create sandbox</button>
            </div>
        </form>
    </section>
</div>
@endsection
