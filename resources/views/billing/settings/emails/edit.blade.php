@extends('layouts.app')
@section('title', 'Edit email · '.$event->label())
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Emails', 'href' => route('billing.settings.emails', ['seller' => $sellerScope->id ?? ''])],
        ['label' => $event->label()],
    ]" />
@endsection

@php
    $sellerId = $sellerScope->id ?? '';
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $previewUrl = route('billing.settings.emails.preview', [$event->value, 'locale' => $locale, 'seller' => $sellerId]);
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.settings.emails', ['seller' => $sellerId])" label="Back to email templates" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $event->label() }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $event->description() }}</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- Scope: which locale + selling entity this template is filed under. Changing either
         reloads the editor for that (event, locale, seller) key. --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <form method="GET" action="{{ route('billing.settings.emails.edit', $event->value) }}" style="display:flex;align-items:center;gap:16px;padding:12px 20px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px">Locale
                <select name="locale" onchange="this.form.submit()" style="{{ $inputStyle }}">
                    @foreach ($locales as $code => $name)
                        <option value="{{ $code }}" @selected($locale === $code)>{{ $name }} ({{ $code }})</option>
                    @endforeach
                </select>
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px">Seller
                <select name="seller" onchange="this.form.submit()" style="{{ $inputStyle }}">
                    @foreach ($sellers as $id => $sname)
                        <option value="{{ $id }}" @selected($sellerId === $id)>{{ $sname }}</option>
                    @endforeach
                </select>
            </label>
            <span style="margin-left:auto;font-size:12px" class="mut">
                Currently rendering from:
                @if ($hasOverride)
                    <span class="cbx-pill cbx-pill--success" style="font-size:10px"><span class="dot"></span>Override at this key</span>
                @else
                    <span class="cbx-pill cbx-pill--muted" style="font-size:10px">{{ $resolvedSource->label() }}</span>
                @endif
            </span>
        </form>
    </section>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:start">

        {{-- Editor --}}
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Template</h2></header>
            <form method="POST" action="{{ route('billing.settings.emails.update', $event->value) }}" id="tpl-form" style="padding:8px 20px 20px;display:flex;flex-direction:column;gap:14px">
                @csrf
                @method('PUT')
                <input type="hidden" name="locale" value="{{ $locale }}">
                <input type="hidden" name="seller" value="{{ $sellerId }}">

                <label style="{{ $labelStyle }}">Subject
                    <input type="text" name="subject" id="tpl-subject" value="{{ old('subject', $subject) }}" required maxlength="300" style="{{ $inputStyle }}">
                    @error('subject')<span style="color:var(--destructive);font-size:11px">{{ $message }}</span>@enderror
                </label>

                <label style="{{ $labelStyle }}">Body
                    <textarea name="body" id="tpl-body" required rows="18" spellcheck="false" style="border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:1.55;resize:vertical">{{ old('body', $body) }}</textarea>
                    @error('body')<span style="color:var(--destructive);font-size:11px">{{ $message }}</span>@enderror
                    <span class="mut" style="font-size:11px">Sandboxed mustache syntax — <span class="num">@{{ variable }}</span> (auto-escaped), <span class="num">@{{#if x}}…@{{else}}…@{{/if}}</span>, <span class="num">@{{#each list}}…@{{/each}}</span>. Never evaluated as PHP/Blade.</span>
                </label>

                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7])Save template</button>
                    @if ($hasOverride)
                        <button type="submit" form="reset-form" class="cbx-btn">Reset to default</button>
                    @endif
                </div>
            </form>

            @if ($hasOverride)
                <form method="POST" action="{{ route('billing.settings.emails.reset', $event->value) }}" id="reset-form"
                      data-confirm="Reset this template to the shipped default? Your saved override for this (event, locale, seller) is deleted." data-confirm-title="Reset to default?" data-confirm-label="Reset" data-confirm-variant="destructive" style="display:none">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">
                    <input type="hidden" name="seller" value="{{ $sellerId }}">
                </form>
            @endif

            {{-- Available variables --}}
            <div style="border-top:1px solid var(--border);padding:14px 20px">
                <h3 class="cbx-panel-title" style="font-size:12px;margin:0 0 8px">Available variables <span class="mut" style="font-weight:400">— click to insert</span></h3>
                <div style="display:flex;flex-direction:column;gap:6px">
                    @foreach ($variables as $name => $meta)
                        @php $token = '{{ '.$name.' }}'; @endphp
                        <button type="button" class="var-chip" data-var="{{ $name }}" style="display:flex;gap:10px;align-items:baseline;text-align:left;background:none;border:0;padding:2px 0;cursor:pointer">
                            <code class="num" style="font-size:11px;color:var(--primary);white-space:nowrap">{{ $token }}</code>
                            <span class="mut" style="font-size:11px">{{ $meta['description'] }}</span>
                        </button>
                    @endforeach
                    <span class="mut" style="font-size:11px;margin-top:4px">Reserved: <code class="num">@{{ brand_color }}</code>, <code class="num">@{{ product_name }}</code>, <code class="num">@{{ support_url }}</code>, <code class="num">@{{ support_email }}</code> — the seller's branding.</span>
                </div>
            </div>
        </section>

        {{-- Live preview + test send --}}
        <section class="cbx-panel" style="position:sticky;top:12px">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
                <h2 class="cbx-panel-title" style="font-size:14px">Live preview</h2>
                <span class="mut" style="font-size:11px">Sample data · {{ $sellers[$sellerId] ?? 'Account-wide' }} · {{ $locale }}</span>
            </header>
            <div style="padding:0 20px 12px">
                <iframe id="tpl-preview" src="{{ $previewUrl }}" sandbox="allow-same-origin" title="Email preview" style="width:100%;height:600px;border:1px solid var(--border);border-radius:10px;background:#faf7f2"></iframe>
                <p class="mut" style="font-size:11px;margin:8px 2px 0">Rendered server-side through the real branded pipeline with this event's sample record — exactly what a customer receives. The preview iframe is sandboxed (no scripts run).</p>
            </div>

            <div style="border-top:1px solid var(--border);padding:14px 20px">
                <h3 class="cbx-panel-title" style="font-size:12px;margin:0 0 8px">Send a test</h3>
                <form method="POST" action="{{ route('billing.settings.emails.test', $event->value) }}" style="display:flex;gap:8px;flex-wrap:wrap">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">
                    <input type="hidden" name="seller" value="{{ $sellerId }}">
                    <input type="email" name="recipient" required placeholder="you@example.com" maxlength="254" style="{{ $inputStyle }};flex:1;min-width:180px">
                    <button type="submit" class="cbx-btn">Send test email</button>
                </form>
                <p class="mut" style="font-size:11px;margin:8px 2px 0">Routes through the real notifier. In test mode it is captured (not delivered); in live mode it is sent.</p>
            </div>
        </section>
    </div>
</div>
@endsection

@section('scripts')
<script>
    (function () {
        var token = @json(csrf_token());
        var previewUrl = @json($previewUrl);
        var iframe = document.getElementById('tpl-preview');
        var subject = document.getElementById('tpl-subject');
        var body = document.getElementById('tpl-body');
        var timer = null;

        // Live server-rendered preview of the UNSAVED draft: POST the current editor content to
        // the preview route and swap it into the sandboxed iframe. Debounced so typing is smooth.
        function refresh() {
            var data = new FormData();
            data.append('_token', token);
            data.append('subject', subject.value);
            data.append('body', body.value);
            fetch(previewUrl, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) { if (html !== null) iframe.srcdoc = html; })
                .catch(function () { /* keep the last good preview */ });
        }
        function schedule() { clearTimeout(timer); timer = setTimeout(refresh, 350); }

        subject.addEventListener('input', schedule);
        body.addEventListener('input', schedule);
        // Render the current draft immediately so the preview reflects unsaved edits on load.
        refresh();

        // Click a variable chip to insert its token at the body cursor.
        Array.prototype.forEach.call(document.querySelectorAll('.var-chip'), function (chip) {
            chip.addEventListener('click', function () {
                // Built by concatenation so Blade never sees a literal double-brace here.
                var snippet = '{' + '{ ' + chip.dataset.var + ' }' + '}';
                var start = body.selectionStart || 0, end = body.selectionEnd || 0;
                body.value = body.value.slice(0, start) + snippet + body.value.slice(end);
                body.focus();
                body.selectionStart = body.selectionEnd = start + snippet.length;
                schedule();
            });
        });
    })();
</script>
@endsection
