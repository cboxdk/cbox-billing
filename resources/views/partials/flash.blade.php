{{--
    Reusable flash + validation banner — standardizes how every mutating surface reports
    success, failure, and validation errors (the plan-price-form pattern). Include once near
    the top of a page's `.page`. It reads the common session keys used across the console so
    a single include covers all pages; waves 2-4 flash `status`/`error` and let this render it.
--}}
@php
    $flashSuccess = collect(['status', 'success', 'catalog_notice', 'license_notice'])
        ->map(fn ($k) => session($k))->first(fn ($v) => filled($v));
    $flashError = collect(['error', 'catalog_error', 'license_error'])
        ->map(fn ($k) => session($k))->first(fn ($v) => filled($v));
@endphp

@if ($flashSuccess)
    <div class="cbx-panel" style="padding:12px 20px;border-left:3px solid var(--success)" role="status">
        <span class="cbx-pill cbx-pill--success" style="margin-right:8px"><span class="dot"></span>Done</span>
        <span>{{ $flashSuccess }}</span>
    </div>
@endif

@if ($flashError)
    <div class="cbx-panel" style="padding:12px 20px;border-left:3px solid var(--destructive)" role="alert">
        <strong style="color:var(--destructive)">Something went wrong.</strong> <span class="mut">{{ $flashError }}</span>
    </div>
@endif

@if ($errors->any())
    <div class="cbx-panel" style="padding:12px 20px;border-left:3px solid var(--destructive)" role="alert">
        <strong style="color:var(--destructive)">Check the form.</strong>
        <ul class="mut" style="margin:6px 0 0;padding-left:18px;font-size:12px">
            @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif
