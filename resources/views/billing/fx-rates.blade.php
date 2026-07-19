@extends('layouts.app')
@section('title', 'FX rates')
@section('crumb', 'Settings')

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">FX rates</h1>
            <p class="cbx-page-desc" style="font-size:13px">Reference rates for consolidated reporting · effective as of {{ $asOf->format('Y-m-d') }} · the ledger always stays in each transaction's own currency</p>
        </div>
        <form method="POST" action="{{ route('billing.settings.fx.refresh') }}">@csrf<button class="cbx-btn cbx-btn--primary">Refresh from sources</button></form>
    </header>

    @include('partials.flash')

    {{-- Provenance is explicit: rates come from a cited public feed or an operator override,
         never fabricated. --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Sources</h2></header>
        <dl style="margin:0;padding:2px 20px 8px">
            <div class="cbx-kv" style="padding:9px 0">
                <dt>ECB reference rates</dt>
                <dd>
                    @if (in_array('ecb', $sources, true))<span class="cbx-pill cbx-pill--success"><span class="dot"></span>enabled</span>@else<span class="cbx-pill cbx-pill--muted"><span class="dot"></span>disabled</span>@endif
                    <span class="mut num" style="margin-left:8px;font-size:11px">{{ $ecbUrl }}</span>
                </dd>
            </div>
            <div class="cbx-kv" style="padding:9px 0">
                <dt>Operator overrides</dt>
                <dd>@if (in_array('override', $sources, true))<span class="cbx-pill cbx-pill--success"><span class="dot"></span>enabled</span>@else<span class="cbx-pill cbx-pill--muted"><span class="dot"></span>disabled</span>@endif <span class="mut" style="margin-left:8px;font-size:12px">treasury-fixed or ECB-uncovered pairs — supersede ECB on the same date/pair</span></dd>
            </div>
        </dl>
        <p class="cbx-page-desc" style="font-size:11px;padding:0 20px 14px;margin:0">Source: European Central Bank, "Euro foreign exchange reference rates" (base EUR). Non-EUR pairs are derived via the EUR pivot at read time. A pair with no rate is reported honestly as unavailable — never converted at a made-up number.</p>
    </section>

    {{-- Current rates: base → quote, the exact stored value, its source and as-of date. --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Current rates</h2><span class="cbx-pill cbx-pill--muted">{{ count($rates) }}</span></header>
        <table class="tbl">
            <thead><tr><th>Base</th><th>Quote</th><th class="right">Rate (1 base = rate quote)</th><th>Source</th><th>As-of</th></tr></thead>
            <tbody>
                @forelse ($rates as $rate)
                    <tr style="cursor:default">
                        <td class="num">{{ $rate->base }}</td>
                        <td class="num">{{ $rate->quote }}</td>
                        <td class="right num">{{ rtrim(rtrim((string) $rate->rate, '0'), '.') }}</td>
                        <td><span class="cbx-pill {{ $rate->origin->value === 'override' ? 'cbx-pill--info' : 'cbx-pill--muted' }}">{{ $rate->origin->label() }}</span></td>
                        <td class="num mut">{{ $rate->asOf->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="mut" style="text-align:center;padding:20px">No rates yet. Run a refresh to pull the ECB feed, or add an override below.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Author an override: a treasury-fixed rate or a pair ECB does not publish. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Add an override rate</h2></header>
        <form method="POST" action="{{ route('billing.settings.fx.overrides') }}" style="padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;align-items:end">
            @csrf
            @php $fld = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px'; $lbl = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500'; @endphp
            <label style="{{ $lbl }}">Base (ISO)<input name="base" maxlength="3" placeholder="USD" required style="{{ $fld }};text-transform:uppercase"></label>
            <label style="{{ $lbl }}">Quote (ISO)<input name="quote" maxlength="3" placeholder="XOF" required style="{{ $fld }};text-transform:uppercase"></label>
            <label style="{{ $lbl }}">Rate<input name="rate" type="text" inputmode="decimal" placeholder="655.957" required style="{{ $fld }}"></label>
            <label style="{{ $lbl }}">As-of date<input name="as_of_date" type="date" value="{{ $asOf->format('Y-m-d') }}" required style="{{ $fld }}"></label>
            <button class="cbx-btn cbx-btn--primary" style="height:32px">Save override</button>
        </form>
    </section>
</div>
@endsection
