@extends('layouts.app')
@section('title', $experiment->name)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Experiments', 'href' => route('billing.experiments')],
        ['label' => $experiment->name],
    ]" />
@endsection

@php
    use App\Billing\Experiments\Enums\ExperimentStatus;

    $variants = $results->variants;
    $leader = $results->leader();

    // Conversion-rate bar chart geometry (inline SVG, no external lib — same style as Analytics).
    $peakRate = 0.0;
    foreach ($variants as $vr) { $peakRate = max($peakRate, $vr->rate); }
    $peakRate = $peakRate > 0 ? $peakRate : 1.0;
    $colW = 132; $chartH = 150; $barW = 66;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px;display:flex;align-items:center;gap:10px">
                {{ $experiment->name }}
                <span class="cbx-pill cbx-pill--{{ $experiment->status->tone() }}">@if($experiment->status->isServing())<span class="dot"></span>@endif{{ $experiment->status->label() }}</span>
            </h1>
            <p class="cbx-page-desc" style="font-size:13px">
                {{ $experiment->key }} · optimising <strong>{{ $experiment->primary_metric->label() }}</strong>
                @if ($experiment->pricingTable)
                    · on <a href="{{ route('billing.pricing-tables.show', $experiment->pricingTable->id) }}" style="color:var(--primary)">/pricing/{{ $experiment->pricingTable->key }}</a>
                @endif
            </p>
        </div>
        <div style="display:flex;gap:8px">
            @if ($experiment->status === ExperimentStatus::Draft)
                <a href="{{ route('billing.experiments.edit', $experiment->id) }}" class="cbx-btn cbx-btn--sm">Edit</a>
                <form method="POST" action="{{ route('billing.experiments.start', $experiment->id) }}" data-confirm="Start this experiment? Visitors of the pricing page will begin seeing assigned variants.">
                    @csrf
                    <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">@include('partials.icon', ['name' => 'play', 'size' => 13, 'sw' => 1.7])Start</button>
                </form>
            @endif
        </div>
    </header>

    @include('partials.flash')

    @if ($experiment->hypothesis)
        <section class="cbx-panel" style="padding:14px 20px">
            <p class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Hypothesis</p>
            <p style="font-size:13px">{{ $experiment->hypothesis }}</p>
        </section>
    @endif

    {{-- Concluded + promoted: the page now serves the winner permanently. --}}
    @if ($experiment->status === ExperimentStatus::Concluded)
        <section class="cbx-panel" style="padding:14px 20px;border-left:3px solid var(--info)">
            @if ($experiment->promotedVariant)
                <p style="font-size:13px">Concluded — <strong>{{ $experiment->promotedVariant->label }}</strong> was promoted. <a href="{{ $publicUrl ?? '#' }}" style="color:var(--primary)">/pricing/{{ $experiment->pricingTable?->key }}</a> now serves its table.</p>
            @else
                <p style="font-size:13px">Concluded with no winner — the pricing page serves its base table.</p>
            @endif
        </section>
    @endif

    {{-- Headline totals --}}
    <div class="stats">
        <div><p class="lbl">Impressions</p><p class="val">{{ number_format($results->totalImpressions) }}</p><span class="delta mut num">deduped visitors</span></div>
        <div><p class="lbl">Conversions</p><p class="val">{{ number_format($results->totalConversions) }}</p><span class="delta mut num">{{ $experiment->primary_metric->label() }}</span></div>
        <div><p class="lbl">Overall rate</p><p class="val">{{ $results->totalImpressions > 0 ? number_format($results->totalConversions / $results->totalImpressions * 100, 2) : '0,00' }}%</p><span class="delta mut num">conv ÷ impr</span></div>
        <div><p class="lbl">Leading variant</p><p class="val" style="font-size:18px">{{ $leader?->variant->label ?? '—' }}</p><span class="delta {{ $leader ? 'up' : 'mut' }} num">{{ $leader ? 'significant @ 95%' : 'no significant lift yet' }}</span></div>
    </div>

    {{-- Per-variant conversion-rate chart --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Conversion rate by variant</h2><p class="cbx-panel-desc" style="font-size:12px">control vs challengers · {{ $experiment->primary_metric->label() }}</p></div>
        </header>
        @if ($results->totalImpressions > 0)
            <div style="overflow-x:auto;padding:18px 20px 8px">
                <svg width="{{ count($variants) * $colW }}" height="{{ $chartH + 48 }}" viewBox="0 0 {{ count($variants) * $colW }} {{ $chartH + 48 }}" style="max-width:100%">
                    @foreach ($variants as $i => $vr)
                        @php
                            $h = max(2, ($vr->rate / $peakRate) * $chartH);
                            $x = $i * $colW + ($colW - $barW) / 2;
                            $y = $chartH - $h;
                            $cx = $i * $colW + $colW / 2;
                            $fill = $vr->isControl ? 'var(--muted-foreground)' : ($vr->significance->significant ? 'var(--success)' : 'var(--primary)');
                        @endphp
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $h }}" rx="4" fill="{{ $fill }}" opacity="0.85"/>
                        <text x="{{ $cx }}" y="{{ $y - 6 }}" text-anchor="middle" font-size="11" fill="var(--foreground)" font-family="var(--font-mono)">{{ number_format($vr->ratePercent(), 2) }}%</text>
                        <text x="{{ $cx }}" y="{{ $chartH + 18 }}" text-anchor="middle" font-size="10" fill="var(--muted-foreground)" font-family="var(--font-sans)">{{ \Illuminate\Support\Str::limit($vr->variant->label, 16) }}{{ $vr->isControl ? ' (ctrl)' : '' }}</text>
                        <text x="{{ $cx }}" y="{{ $chartH + 34 }}" text-anchor="middle" font-size="10" fill="var(--muted-foreground)" font-family="var(--font-mono)">{{ number_format($vr->conversions) }}/{{ number_format($vr->impressions) }}</text>
                    @endforeach
                </svg>
            </div>
        @else
            <p class="mut" style="padding:24px;text-align:center">No impressions recorded yet — start the experiment and drive traffic to the pricing page.</p>
        @endif

        <table class="tbl">
            <thead><tr><th>Variant</th><th>Serves</th><th class="right" style="width:90px">Impr.</th><th class="right" style="width:90px">Conv.</th><th class="right" style="width:90px">Rate</th><th class="right" style="width:90px">Lift</th><th class="right" style="width:130px">Significance</th></tr></thead>
            <tbody>
                @foreach ($variants as $vr)
                    <tr style="cursor:default">
                        <td>
                            <span style="font-weight:600">{{ $vr->variant->label }}</span>
                            @if ($vr->isControl)<span class="cbx-pill cbx-pill--muted" style="margin-left:6px">control</span>@endif
                            @if ($experiment->promoted_variant_id === $vr->variant->id)<span class="cbx-pill cbx-pill--info" style="margin-left:6px">winner</span>@endif
                        </td>
                        <td class="mut num" style="font-size:11px">{{ $vr->variant->servedTable?->key ? '/pricing/'.$vr->variant->servedTable->key : 'base table' }} · w{{ $vr->variant->weight }}</td>
                        <td class="right num">{{ number_format($vr->impressions) }}</td>
                        <td class="right num">{{ number_format($vr->conversions) }}</td>
                        <td class="right num">{{ number_format($vr->ratePercent(), 2) }}%</td>
                        <td class="right num {{ $vr->lift === null ? 'mut' : ($vr->lift > 0 ? 'up' : ($vr->lift < 0 ? 'down' : 'mut')) }}">
                            @if ($vr->liftPercent() === null)—@else{{ $vr->lift > 0 ? '+' : '' }}{{ number_format($vr->liftPercent(), 1) }}%@endif
                        </td>
                        <td class="right num">
                            @if ($vr->isControl)
                                <span class="mut">baseline</span>
                            @elseif ($vr->significance->significant)
                                <span class="up">{{ $vr->significance->confidencePercent() }}% conf.</span>
                            @else
                                <span class="mut">{{ $vr->significance->confidencePercent() }}% conf.</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Honest caveat about the significance statistic. --}}
    <section class="cbx-panel" style="padding:14px 20px;border-left:3px solid var(--warning)">
        <p class="mut" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">About the significance signal</p>
        <p style="font-size:12px;line-height:1.6">
            Significance is a <strong>two-proportion z-test</strong> against the control — a fixed-horizon frequentist test. It is a
            <strong>guide, not a verdict</strong>. Its confidence is only valid if you decide the sample size up front and read it once;
            watching the dashboard and stopping the moment a variant crosses 95% (“peeking”) inflates false positives well beyond 5%.
            With only a handful of conversions the number is noise. Treat a green signal as “worth a closer look”, not “ship it”.
        </p>
    </section>

    {{-- Conclude + promote (running experiments only). --}}
    @if ($experiment->status === ExperimentStatus::Running)
        <section class="cbx-panel" style="padding:20px">
            <h2 class="cbx-panel-title" style="font-size:14px;margin-bottom:6px">Conclude experiment</h2>
            <p class="cbx-panel-desc" style="font-size:12px;margin-bottom:12px">Stop the test. Optionally promote a winning variant — the pricing page will then serve that variant's table permanently. Promotion is non-destructive and can be changed later.</p>
            <form method="POST" action="{{ route('billing.experiments.conclude', $experiment->id) }}" data-confirm="Conclude this experiment? Variant assignment stops immediately." style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                @csrf
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Promote winner
                    <select name="winner" style="height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px;min-width:240px">
                        <option value="">No winner — revert to base table</option>
                        @foreach ($variants as $vr)
                            <option value="{{ $vr->variant->id }}" @selected($leader && $leader->variant->id === $vr->variant->id)>{{ $vr->variant->label }} — {{ number_format($vr->ratePercent(), 2) }}%{{ $leader && $leader->variant->id === $vr->variant->id ? ' (leader)' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Conclude</button>
            </form>
        </section>
    @endif

    {{-- Danger zone: delete (draft/concluded only — a running experiment must be concluded first). --}}
    @if ($experiment->status !== ExperimentStatus::Running)
        <section class="cbx-panel" style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between">
            <div><p style="font-size:13px;font-weight:600">Delete experiment</p><p class="mut" style="font-size:12px">Removes the experiment and its recorded impressions and conversions. The pricing tables are untouched.</p></div>
            <form method="POST" action="{{ route('billing.experiments.destroy', $experiment->id) }}" data-confirm="Delete “{{ $experiment->name }}” and all its recorded results? This cannot be undone.">
                @csrf
                @method('DELETE')
                <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Delete</button>
            </form>
        </section>
    @endif
</div>
@endsection
