@extends('layouts.app')
@section('title', $table->name)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Pricing tables', 'href' => route('billing.pricing-tables')],
        ['label' => $table->name],
    ]" />
@endsection

@php
    $iframeSnippet = '<iframe src="'.e($embedUrl).'" title="Pricing" loading="lazy" style="width:100%;border:0;min-height:640px"></iframe>';
    $scriptSnippet = '<script async src="'.e($loaderUrl).'"></'.'script>';
    $currencies = $table->currencies ?? [];
@endphp

@section('screen')
<div class="page">
    <x-back-button :href="route('billing.pricing-tables')" label="Back to pricing tables" />

    @include('partials.flash')

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $table->name }}
                @if ($table->active)<span class="cbx-pill cbx-pill--success" style="margin-left:6px"><span class="dot"></span>live</span>@else<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">offline</span>@endif
            </h1>
            <p class="cbx-page-desc num" style="font-size:13px">/pricing/{{ $table->key }}</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="cbx-btn cbx-btn--sm">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 13, 'sw' => 1.7])View public</a>
            <a href="{{ route('billing.pricing-tables.edit', $table->id) }}" class="cbx-btn cbx-btn--sm">Edit</a>
            @if ($table->active)
                <form method="POST" action="{{ route('billing.pricing-tables.deactivate', $table->id) }}" style="margin:0"
                      data-confirm="Take “{{ $table->name }}” offline? The public /pricing/{{ $table->key }} page will 404 until you set it live again." data-confirm-title="Take offline?" data-confirm-label="Take offline" data-confirm-variant="primary">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Take offline</button></form>
            @else
                <form method="POST" action="{{ route('billing.pricing-tables.activate', $table->id) }}" style="margin:0">@csrf<button type="submit" class="cbx-btn cbx-btn--sm">Set live</button></form>
            @endif
            <form method="POST" action="{{ route('billing.pricing-tables.destroy', $table->id) }}" style="margin:0"
                  data-confirm="Delete “{{ $table->name }}”? This cannot be undone. Your plans and prices are untouched." data-confirm-title="Delete pricing table?" data-confirm-label="Delete" data-confirm-variant="destructive">
                @csrf @method('DELETE')
                <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
            </form>
        </div>
    </header>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:18px;align-items:start" class="cbx-pt-showgrid">
        {{-- Definition --}}
        <section style="display:flex;flex-direction:column;gap:18px">
            <div class="cbx-panel">
                <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Configuration</h2></header>
                <dl class="cbx-kv" style="padding:8px 20px 16px">
                    <dt>Public slug</dt><dd class="num">{{ $table->key }}</dd>
                    <dt>Branding</dt><dd>{{ $table->sellerEntity?->legal_name ?? 'Default / app-level' }}</dd>
                    <dt>Currencies</dt><dd>{{ $currencies === [] ? 'All priced currencies' : implode(', ', $currencies) }}</dd>
                    <dt>Default currency</dt><dd>{{ $table->default_currency ?? '—' }}</dd>
                    <dt>Interval toggle</dt><dd>{{ $table->interval_toggle ? 'Monthly / yearly (where an annual plan is set)' : 'Off' }}</dd>
                    <dt>CTA label</dt><dd>{{ $table->cta_label ?? 'Get started' }}</dd>
                    <dt>CTA target</dt><dd class="num" style="word-break:break-all;font-size:11px">{{ $table->cta_url_template ?? 'Default checkout URL' }}</dd>
                </dl>
            </div>

            <div class="cbx-panel">
                <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Plan columns <span class="mut" style="font-weight:400">— {{ $table->columns->count() }}</span></h2></header>
                <table class="tbl">
                    <thead><tr><th>Plan</th><th style="width:120px">Annual</th><th style="width:90px">Featured</th></tr></thead>
                    <tbody>
                        @forelse ($table->columns as $col)
                            <tr>
                                <td><span style="font-weight:600">{{ $col->plan?->name ?? '—' }}</span>@if ($col->badge) <span class="cbx-pill cbx-pill--info" style="margin-left:6px">{{ $col->badge }}</span>@endif</td>
                                <td class="mut">{{ $col->annualPlan?->name ?? '—' }}</td>
                                <td>@if ($col->featured)<span class="cbx-pill cbx-pill--success"><span class="dot"></span>yes</span>@else<span class="mut">no</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="mut" style="padding:14px 20px">No plan columns yet — <a href="{{ route('billing.pricing-tables.edit', $table->id) }}" style="color:var(--primary)">add some</a>.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="cbx-panel">
                <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Compared features <span class="mut" style="font-weight:400">— {{ $table->featureRows->count() }}</span></h2></header>
                <div style="padding:12px 20px 16px;display:flex;flex-wrap:wrap;gap:6px">
                    @forelse ($table->featureRows as $row)
                        <span class="cbx-pill cbx-pill--muted">{{ $row->feature?->name ?? '—' }}</span>
                    @empty
                        <span class="mut" style="font-size:12px">No feature comparison rows selected.</span>
                    @endforelse
                </div>
            </div>

            {{-- Embed snippet --}}
            <div class="cbx-panel" style="border-left:3px solid var(--primary)">
                <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">@include('partials.icon', ['name' => 'copy', 'size' => 13, 'sw' => 1.7]) Embed on your site</h2></header>
                <div style="padding:8px 20px 18px;display:flex;flex-direction:column;gap:14px">
                    <div>
                        <div style="font-size:12px;font-weight:600;margin-bottom:5px">iframe <span class="mut" style="font-weight:400">— CSP-safe, no script needed (recommended)</span></div>
                        <textarea readonly rows="2" onclick="this.select()" aria-label="iframe embed snippet"
                            style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;border:1px solid var(--border);border-radius:8px;background:var(--secondary);color:var(--foreground);padding:10px">{{ $iframeSnippet }}</textarea>
                    </div>
                    <div>
                        <div style="font-size:12px;font-weight:600;margin-bottom:5px">Script <span class="mut" style="font-weight:400">— injects + auto-sizes the iframe; allow this origin in your CSP</span></div>
                        <textarea readonly rows="1" onclick="this.select()" aria-label="script embed snippet"
                            style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;border:1px solid var(--border);border-radius:8px;background:var(--secondary);color:var(--foreground);padding:10px">{{ $scriptSnippet }}</textarea>
                    </div>
                    <p class="mut" style="font-size:11px;margin:0">Public page: <a href="{{ $publicUrl }}" target="_blank" rel="noopener" style="color:var(--primary)">{{ $publicUrl }}</a></p>
                </div>
            </div>
        </section>

        {{-- Live preview --}}
        <section class="cbx-panel" style="position:sticky;top:12px">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
                <h2 class="cbx-panel-title" style="font-size:14px">Live preview</h2>
                <span class="mut" style="font-size:11px">The actual embedded table</span>
            </header>
            <div style="padding:0 16px 16px">
                <iframe src="{{ route('billing.pricing-tables.preview', $table->id) }}" title="Pricing table preview"
                        sandbox="allow-scripts allow-same-origin allow-popups"
                        style="width:100%;height:720px;border:1px solid var(--border);border-radius:12px;background:var(--background)"></iframe>
                <p class="mut" style="font-size:11px;margin:8px 2px 0">Rendered through the real public storefront view — exactly what a marketing site embeds. Currency and interval toggles are live.</p>
            </div>
        </section>
    </div>
</div>
@endsection

@section('scripts')
<style>@media (max-width: 900px) { .cbx-pt-showgrid { grid-template-columns: 1fr !important; } }</style>
@endsection
