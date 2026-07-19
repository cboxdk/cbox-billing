@extends('layouts.app')
@section('title', $endpoint ? 'Edit webhook endpoint' : 'Register webhook endpoint')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Webhooks', 'href' => route('billing.settings.webhooks')],
        ['label' => $endpoint ? 'Edit' : 'Register'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $action = $endpoint ? route('billing.settings.webhooks.update', $endpoint) : route('billing.settings.webhooks.store');
    $selected = collect($selected);
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.settings.webhooks')" label="Back to webhooks" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $endpoint ? 'Edit endpoint' : 'Register endpoint' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Billing events are POSTed to this URL, signed with the endpoint's secret. The URL must resolve to a public address — private, loopback, and link-local targets are refused.</p>
        </div>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($endpoint) @method('PUT') @endif

            <label style="{{ $labelStyle }}">Endpoint URL
                <input type="url" name="url" value="{{ old('url', $endpoint->url ?? '') }}" required maxlength="2048" placeholder="https://api.example.com/webhooks/cbox" style="{{ $inputStyle }}">
                @error('url') <span style="font-size:11px;color:var(--destructive)">{{ $message }}</span> @enderror
            </label>

            <label style="{{ $labelStyle }}">Description <span class="mut" style="font-weight:400">(optional)</span>
                <input type="text" name="description" value="{{ old('description', $endpoint->description ?? '') }}" maxlength="255" placeholder="Production event sync" style="{{ $inputStyle }}">
            </label>

            <div style="display:flex;flex-direction:column;gap:8px">
                <span style="font-size:12px;font-weight:500">Subscribed events</span>
                @error('event_types') <span style="font-size:11px;color:var(--destructive)">{{ $message }}</span> @enderror
                @foreach ($catalog as $group => $events)
                    <div class="cbx-panel" style="padding:10px 14px;background:var(--card)">
                        <div class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">{{ $group }}</div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px">
                            @foreach ($events as $event)
                                <label style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:400;cursor:pointer">
                                    <input type="checkbox" name="event_types[]" value="{{ $event->value }}"
                                        @checked($selected->contains($event->value) || collect(old('event_types', []))->contains($event->value))>
                                    <span>{{ $event->label() }} <span class="num mut" style="font-size:11px">{{ $event->value }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">{{ $endpoint ? 'Save changes' : 'Register endpoint' }}</button>
                <a href="{{ route('billing.settings.webhooks') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
