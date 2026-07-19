@extends('layouts.app')
@section('title', 'Webhook delivery log')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Webhooks', 'href' => route('billing.settings.webhooks')],
        ['label' => 'Delivery log'],
    ]" />
@endsection

@php
    $pill = [
        'delivered' => 'cbx-pill--success',
        'pending' => 'cbx-pill--muted',
        'failed' => 'cbx-pill--warning',
        'dead' => 'cbx-pill--muted',
    ];
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.settings.webhooks')" label="Back to webhooks" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Delivery log</h1>
            <p class="cbx-page-desc num" style="font-size:12px">{{ $endpoint->url }}</p>
        </div>
        <form method="POST" action="{{ route('billing.settings.webhooks.test', $endpoint) }}">@csrf
            <button type="submit" class="cbx-btn">Send test event</button>
        </form>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Recent deliveries</h2></header>
        @if ($deliveries->isEmpty())
            <div style="padding:24px 20px;text-align:center"><p class="mut" style="font-size:13px;margin:0">No deliveries yet. Send a test event to verify wiring.</p></div>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:12px">
                    <thead>
                        <tr style="text-align:left;color:var(--muted-foreground)">
                            <th style="padding:8px 20px;font-weight:500">Event</th>
                            <th style="padding:8px 12px;font-weight:500">Status</th>
                            <th style="padding:8px 12px;font-weight:500">HTTP</th>
                            <th style="padding:8px 12px;font-weight:500">Attempts</th>
                            <th style="padding:8px 12px;font-weight:500">When</th>
                            <th style="padding:8px 20px;font-weight:500"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deliveries as $delivery)
                            <tr style="border-top:1px solid var(--border)">
                                <td style="padding:9px 20px">
                                    <div class="num">{{ $delivery->event_type }}</div>
                                    <div class="mut num" style="font-size:11px">{{ $delivery->event_id }}</div>
                                </td>
                                <td style="padding:9px 12px"><span class="cbx-pill {{ $pill[$delivery->status->value] ?? 'cbx-pill--muted' }}">{{ $delivery->status->label() }}</span></td>
                                <td style="padding:9px 12px" class="num">{{ $delivery->response_code ?? '—' }}</td>
                                <td style="padding:9px 12px" class="num">{{ $delivery->attempt }}</td>
                                <td style="padding:9px 12px" class="mut">{{ $delivery->created_at->diffForHumans() }}</td>
                                <td style="padding:9px 20px;text-align:right">
                                    @if ($delivery->status->isRedeliverable())
                                        <form method="POST" action="{{ route('billing.settings.webhooks.redeliver', [$endpoint, $delivery]) }}" style="margin:0">@csrf
                                            <button type="submit" class="cbx-btn cbx-btn--sm">Redeliver</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
