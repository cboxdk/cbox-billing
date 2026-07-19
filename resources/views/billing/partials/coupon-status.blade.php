@php($status = $status ?? 'live')
@switch($status)
    @case('live')
        <span class="cbx-pill cbx-pill--success"><span class="dot"></span>live</span>
        @break
    @case('expired')
        <span class="cbx-pill cbx-pill--warning">expired</span>
        @break
    @case('exhausted')
        <span class="cbx-pill cbx-pill--warning">exhausted</span>
        @break
    @case('inactive')
        <span class="cbx-pill cbx-pill--muted">inactive</span>
        @break
    @default
        <span class="cbx-pill cbx-pill--muted">archived</span>
@endswitch
