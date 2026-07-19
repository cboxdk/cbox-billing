@extends('layouts.app')
@section('title', 'Audit event #'.$event->sequence)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Audit', 'href' => route('billing.audit')],
        ['label' => 'Event #'.$event->sequence],
    ]" />
@endsection

@php
    use App\Billing\Audit\Support\AuditTargetLink;
    $link = AuditTargetLink::for($event);
    $before = $event->before();
    $after = $event->after();
    $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    $context = collect($event->metadata ?? [])->except(['before', 'after']);
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $event->action }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $event->summary }}</p>
        </div>
        <a href="{{ route('billing.audit') }}" class="cbx-btn cbx-btn--sm">Back to log</a>
    </header>

    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Event</h2></header>
        <table class="tbl">
            <tbody>
                <tr><td class="mut" style="width:180px">Sequence</td><td class="num">{{ $event->sequence }}</td></tr>
                <tr><td class="mut">Occurred at</td><td>{{ $event->occurred_at->format('Y-m-d H:i:s') }} UTC</td></tr>
                <tr><td class="mut">Actor</td><td>@if ($event->actor_sub === 'system')<span class="cbx-pill cbx-pill--muted">system</span>@else <strong>{{ $event->actor_name ?? '—' }}</strong> · <span class="num">{{ $event->actor_sub }}</span>@endif</td></tr>
                <tr><td class="mut">IP</td><td class="num">{{ $event->actor_ip ?? '—' }}</td></tr>
                <tr><td class="mut">Action</td><td><span class="cbx-pill cbx-pill--muted">{{ $event->action }}</span></td></tr>
                <tr><td class="mut">Target</td><td>
                    @if ($event->target_type)
                        {{ $event->target_type }} · <span class="num">{{ $event->target_id }}</span>
                        @if ($link) — <a href="{{ $link }}">open resource</a>@endif
                    @else — @endif
                </td></tr>
                <tr><td class="mut">Organization</td><td class="num">{{ $event->organization_id ?? '—' }}</td></tr>
                <tr><td class="mut">Plane</td><td><span class="cbx-pill {{ $event->livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $event->livemode ? 'live' : 'test' }}</span></td></tr>
            </tbody>
        </table>
    </section>

    @if ($keys !== [])
        <section class="cbx-panel" style="margin-bottom:14px">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Before / after</h2></header>
            <table class="tbl">
                <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                <tbody>
                    @foreach ($keys as $key)
                        @php
                            $b = $before[$key] ?? null; $a = $after[$key] ?? null;
                            $changed = json_encode($b) !== json_encode($a);
                        @endphp
                        <tr>
                            <td class="mut num" style="font-size:12px">{{ $key }}</td>
                            <td class="num" style="font-size:12px">{{ is_scalar($b) || $b === null ? ($b ?? '—') : json_encode($b) }}</td>
                            <td class="num" style="font-size:12px;{{ $changed ? 'color:var(--foreground);font-weight:600' : '' }}">{{ is_scalar($a) || $a === null ? ($a ?? '—') : json_encode($a) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($context->isNotEmpty())
        <section class="cbx-panel" style="margin-bottom:14px">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Context</h2></header>
            <div style="padding:12px 20px"><pre style="margin:0;font-size:11px;white-space:pre-wrap;word-break:break-word">{{ json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        </section>
    @endif

    {{-- The hash-chain link, so an operator can eyeball the integrity fields --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Hash chain</h2></header>
        <table class="tbl">
            <tbody>
                <tr><td class="mut" style="width:180px">prev_hash</td><td class="num" style="font-size:11px;word-break:break-all">{{ $event->prev_hash }}</td></tr>
                <tr><td class="mut">hash</td><td class="num" style="font-size:11px;word-break:break-all">{{ $event->hash }}</td></tr>
            </tbody>
        </table>
    </section>
</div>
@endsection
