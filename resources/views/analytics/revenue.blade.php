@extends('layouts.app')
@section('title', 'Revenue analytics')
@section('crumb', 'Analytics')

@php
    use App\Billing\Support\MoneyFormatter;

    // Build the MRR-movement waterfall steps in exact minor units — the same figures the
    // engine reconciled (start + new + expansion − contraction − churn + reactivation = end).
    $steps = [];
    if ($waterfall) {
        $run = $waterfall->startMrr->minor();
        $push = function (string $label, int $delta, string $kind) use (&$steps, &$run) {
            $from = $run;
            $run += $delta;
            $steps[] = ['label' => $label, 'from' => min($from, $run), 'to' => max($from, $run), 'value' => $delta, 'kind' => $kind];
        };
        $steps[] = ['label' => 'Start', 'from' => 0, 'to' => $waterfall->startMrr->minor(), 'value' => $waterfall->startMrr->minor(), 'kind' => 'base'];
        $push('New', $waterfall->new->minor(), 'up');
        $push('Expansion', $waterfall->expansion->minor(), 'up');
        $push('Contraction', -$waterfall->contraction->minor(), 'down');
        $push('Churn', -$waterfall->churn->minor(), 'down');
        $push('Reactivation', $waterfall->reactivation->minor(), 'up');
        $steps[] = ['label' => 'End', 'from' => 0, 'to' => $waterfall->endMrr->minor(), 'value' => $waterfall->endMrr->minor(), 'kind' => 'base'];
    }
    $peak = 1;
    foreach ($steps as $s) { $peak = max($peak, $s['to']); }

    $chartH = 150; $colW = 92; $gap = 16; $barW = 46;
    $fill = ['base' => 'var(--primary)', 'up' => 'var(--success)', 'down' => 'var(--destructive)'];
    $net = $waterfall ? $waterfall->netChange() : null;
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Revenue analytics</h1>
            <p class="cbx-page-desc" style="font-size:13px">MRR movement &amp; ARR · engine-computed over the seller book · {{ $windowStart }} → {{ $windowEnd }}</p>
        </div>
        <div class="filters" style="margin:0">
            @foreach ($currencies as $cur)
                <a class="fchip {{ $currency === $cur ? 'set' : '' }}" href="{{ route('analytics.revenue', array_filter(['currency' => $cur, 'reporting' => $reporting, 'entity' => $entityId])) }}">{{ $cur }}</a>
            @endforeach
        </div>
    </header>

    {{-- ── Consolidated (multi-entity · multi-currency) ─────────────────────────────────────
         The whole book normalized to ONE reporting currency with real FX. The ledger stays in
         each transaction's currency; this overlay only reports. Rates come from the fx_rates
         store (ECB / operator override) — never fabricated; a currency with no rate is shown as
         unavailable, not converted at a made-up number. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div>
                <h2 class="cbx-panel-title" style="font-size:14px">Consolidated recurring revenue</h2>
                <p class="cbx-panel-desc" style="font-size:12px">every entity &amp; currency → {{ $reporting }} · MRR uses today's effective rate</p>
            </div>
            <form method="GET" action="{{ route('analytics.revenue') }}" class="filters" style="margin:0;gap:8px;align-items:center">
                <input type="hidden" name="currency" value="{{ $currency }}">
                <label style="font-size:12px;color:var(--muted-foreground)">Reporting
                    <select name="reporting" onchange="this.form.submit()" style="height:30px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:12px;margin-left:4px">
                        @foreach ($reportingOptions as $opt)
                            <option value="{{ $opt }}" {{ $reporting === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="font-size:12px;color:var(--muted-foreground)">Entity
                    <select name="entity" onchange="this.form.submit()" style="height:30px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:12px;margin-left:4px">
                        <option value="">All entities</option>
                        @foreach ($entityOptions as $opt)
                            <option value="{{ $opt['id'] }}" {{ $entityId === $opt['id'] ? 'selected' : '' }}>{{ $opt['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        </header>

        <div class="stats" style="padding:4px 4px 0">
            <div>
                <p class="lbl">Consolidated MRR</p>
                <p class="val">{{ MoneyFormatter::money($consolidated->mrr) }}</p>
                <span class="delta mut num">{{ $consolidated->subscriptions }} contributing · {{ $reporting }}{{ $entityId ? ' · '.collect($entityOptions)->firstWhere('id', $entityId)['name'] : ' · all entities' }}</span>
            </div>
            <div>
                <p class="lbl">Consolidated ARR</p>
                <p class="val">{{ MoneyFormatter::money($consolidated->arr) }}</p>
                <span class="delta mut num">MRR × 12</span>
            </div>
            <div>
                <p class="lbl">Selling entities</p>
                <p class="val num">{{ count($consolidated->byEntity) }}</p>
                <span class="delta mut num">billing currencies: {{ count($consolidated->byCurrency) }}</span>
            </div>
            <div>
                <p class="lbl">FX coverage</p>
                <p class="val" style="font-size:18px">{{ $consolidated->complete() ? 'Complete' : 'Partial' }}</p>
                <span class="delta {{ $consolidated->complete() ? 'up' : 'down' }} num">{{ $consolidated->complete() ? 'all currencies converted' : 'unavailable: '.implode(', ', $consolidated->unavailable) }}</span>
            </div>
        </div>

        {{-- Per-currency: native → converted with the exact rate + as-of date, so the number is
             auditable, never a black box. --}}
        <table class="tbl">
            <thead><tr>
                <th>Currency</th><th class="right">Subs</th>
                <th class="right">Native MRR</th>
                <th>Rate → {{ $reporting }}</th><th>Source · as-of</th>
                <th class="right">Converted ({{ $reporting }})</th>
            </tr></thead>
            <tbody>
                @foreach ($consolidated->byCurrency as $cl)
                    <tr style="cursor:default">
                        <td class="num">{{ $cl->currency }}</td>
                        <td class="right num">{{ $cl->subscriptions }}</td>
                        <td class="right num">{{ MoneyFormatter::money($cl->native) }}</td>
                        @if ($cl->available() && $cl->rate)
                            <td class="num">{{ $cl->currency === $reporting ? '1 (base)' : $cl->rate->decimal(6) }}</td>
                            <td class="mut num">{{ $cl->rate->origin->label() }} · {{ $cl->rate->asOf->format('Y-m-d') }}</td>
                            <td class="right num">{{ MoneyFormatter::money($cl->converted) }}</td>
                        @else
                            <td class="num mut">—</td>
                            <td class="mut">no rate</td>
                            <td class="right"><span class="cbx-pill cbx-pill--warning">rate unavailable</span></td>
                        @endif
                    </tr>
                @endforeach
                <tr style="cursor:default;font-weight:600;border-top:2px solid var(--border-strong)">
                    <td>Consolidated</td><td class="right num">{{ $consolidated->subscriptions }}</td>
                    <td></td><td></td><td></td>
                    <td class="right num">{{ MoneyFormatter::money($consolidated->mrr) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    {{-- Per-entity roll-up: each selling entity's native currencies → consolidated. This is the
         multi-subsidiary view — one row per legal selling entity. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">By selling entity</h2><p class="cbx-panel-desc" style="font-size:12px">consolidated to {{ $reporting }}</p></div>
        </header>
        <table class="tbl">
            <thead><tr><th>Entity</th><th class="right">Subs</th><th>Native currencies</th><th class="right">Consolidated ({{ $reporting }})</th></tr></thead>
            <tbody>
                @forelse ($consolidated->byEntity as $el)
                    <tr style="cursor:default">
                        <td>{{ $el->entityName }} @unless($el->complete)<span class="cbx-pill cbx-pill--warning" style="margin-left:6px">partial FX</span>@endunless</td>
                        <td class="right num">{{ $el->subscriptions }}</td>
                        <td class="num mut">{{ collect($el->currencies)->map(fn($c) => $c->currency.' '.MoneyFormatter::money($c->native))->implode(' · ') }}</td>
                        <td class="right num">{{ MoneyFormatter::money($el->consolidated) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="mut" style="text-align:center;padding:20px">No contributing subscriptions.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Consolidated MRR-movement bridge: each currency's native waterfall converted at the
         period-end rate and summed; the ending MRR is the accounting identity over the converted
         components, so it reconciles exactly. NRR/GRR are the consolidated retention. --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Consolidated MRR movement</h2><p class="cbx-panel-desc" style="font-size:12px">FX as-of {{ $consolidatedMovement->asOf->format('Y-m-d') }} (period-end basis) · {{ $reporting }}</p></div>
            @if ($consolidatedMovement->retention)
                <span class="cbx-pill cbx-pill--info">NRR {{ number_format($consolidatedMovement->retention->nrr->basisPoints() / 100, 1) }}% · GRR {{ number_format($consolidatedMovement->retention->grr->basisPoints() / 100, 1) }}%</span>
            @endif
        </header>
        @php $cw = $consolidatedMovement->waterfall; @endphp
        <table class="tbl">
            <thead><tr><th>Component</th><th class="right" style="width:200px">Amount ({{ $reporting }})</th></tr></thead>
            <tbody>
                <tr style="cursor:default"><td>Starting MRR</td><td class="right num">{{ MoneyFormatter::money($cw->startMrr) }}</td></tr>
                <tr style="cursor:default"><td><span class="up">New business</span></td><td class="right num up">+{{ MoneyFormatter::money($cw->new) }}</td></tr>
                <tr style="cursor:default"><td><span class="up">Expansion</span></td><td class="right num up">+{{ MoneyFormatter::money($cw->expansion) }}</td></tr>
                <tr style="cursor:default"><td><span class="down">Contraction</span></td><td class="right num down">−{{ MoneyFormatter::money($cw->contraction) }}</td></tr>
                <tr style="cursor:default"><td><span class="down">Churn</span></td><td class="right num down">−{{ MoneyFormatter::money($cw->churn) }}</td></tr>
                <tr style="cursor:default"><td><span class="up">Reactivation</span></td><td class="right num up">+{{ MoneyFormatter::money($cw->reactivation) }}</td></tr>
                <tr style="cursor:default;font-weight:600"><td>Ending MRR</td><td class="right num">{{ MoneyFormatter::money($cw->endMrr) }}</td></tr>
            </tbody>
        </table>
        @if ($consolidatedMovement->unavailable !== [])
            <p class="mut" style="padding:12px 20px;font-size:12px">Excluded (no period-end rate): {{ implode(', ', $consolidatedMovement->unavailable) }}.</p>
        @endif
    </section>

    <h2 class="cbx-page-title" style="font-size:15px;margin:8px 4px 0">Per-currency detail · {{ $currency }}</h2>

    {{-- Headline recurring revenue — summed by the engine MrrCalculator under its state→MRR policy --}}
    <div class="stats">
        <div>
            <p class="lbl">MRR</p>
            <p class="val">{{ $line ? MoneyFormatter::money($line->mrr) : '—' }}</p>
            <span class="delta mut num">{{ $line->subscriptions ?? 0 }} contributing · {{ $currency }}</span>
        </div>
        <div>
            <p class="lbl">ARR</p>
            <p class="val">{{ $line ? MoneyFormatter::money($line->arr) : '—' }}</p>
            <span class="delta mut num">MRR × 12</span>
        </div>
        <div>
            <p class="lbl">Net new MRR</p>
            <p class="val">{{ $net ? MoneyFormatter::money($net) : '—' }}</p>
            <span class="delta {{ $net && $net->isNegative() ? 'down' : ($net && $net->isPositive() ? 'up' : 'mut') }} num">this window</span>
        </div>
        <div>
            <p class="lbl">Gross new / lost</p>
            <p class="val" style="font-size:18px">
                @if($waterfall)<span class="up">+{{ number_format(($waterfall->new->minor() + $waterfall->expansion->minor() + $waterfall->reactivation->minor()) / 100, 0, ',', '.') }}</span> / <span class="down">−{{ number_format(($waterfall->contraction->minor() + $waterfall->churn->minor()) / 100, 0, ',', '.') }}</span>@else—@endif
            </p>
            <span class="delta mut num">added · lost · {{ $currency }}</span>
        </div>
    </div>

    {{-- MRR movement waterfall — inline SVG floating bars, no external chart lib --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">MRR movement</h2><p class="cbx-panel-desc" style="font-size:12px">new · expansion · contraction · churn · reactivation</p></div>
            <span class="cbx-pill cbx-pill--info">{{ $currency }}</span>
        </header>
        @if ($waterfall)
            <div style="overflow-x:auto;padding:18px 20px 8px">
                <svg width="{{ count($steps) * ($colW) }}" height="{{ $chartH + 46 }}" viewBox="0 0 {{ count($steps) * $colW }} {{ $chartH + 46 }}" style="max-width:100%">
                    @foreach ($steps as $i => $s)
                        @php
                            $x = $i * $colW + ($colW - $barW) / 2;
                            $yTop = $chartH - ($s['to'] / $peak) * $chartH;
                            $h = max(2, (($s['to'] - $s['from']) / $peak) * $chartH);
                            $cx = $i * $colW + $colW / 2;
                        @endphp
                        {{-- connector to the next bar --}}
                        @if (!$loop->last)
                            <line x1="{{ $x + $barW }}" y1="{{ $chartH - ($s['to'] / $peak) * $chartH }}" x2="{{ ($i + 1) * $colW + ($colW - $barW) / 2 }}" y2="{{ $chartH - ($s['to'] / $peak) * $chartH }}" stroke="var(--border-strong)" stroke-width="1" stroke-dasharray="2 2"/>
                        @endif
                        <rect x="{{ $x }}" y="{{ $yTop }}" width="{{ $barW }}" height="{{ $h }}" rx="3" fill="{{ $fill[$s['kind']] }}" opacity="{{ $s['kind'] === 'base' ? 0.9 : 0.82 }}"/>
                        <text x="{{ $cx }}" y="{{ $chartH + 16 }}" text-anchor="middle" font-size="10" fill="var(--muted-foreground)" font-family="var(--font-sans)">{{ $s['label'] }}</text>
                        <text x="{{ $cx }}" y="{{ $chartH + 32 }}" text-anchor="middle" font-size="10" fill="var(--foreground)" font-family="var(--font-mono)">{{ ($s['kind'] === 'down' ? '−' : ($s['kind'] === 'up' ? '+' : '')) }}{{ number_format(abs($s['value']) / 100, 0, ',', '.') }}</text>
                    @endforeach
                </svg>
            </div>
            <table class="tbl">
                <thead><tr><th>Component</th><th class="right" style="width:180px">Amount ({{ $currency }})</th></tr></thead>
                <tbody>
                    <tr style="cursor:default"><td>Starting MRR</td><td class="right num">{{ MoneyFormatter::money($waterfall->startMrr) }}</td></tr>
                    <tr style="cursor:default"><td><span class="up">New business</span></td><td class="right num up">+{{ MoneyFormatter::money($waterfall->new) }}</td></tr>
                    <tr style="cursor:default"><td><span class="up">Expansion</span></td><td class="right num up">+{{ MoneyFormatter::money($waterfall->expansion) }}</td></tr>
                    <tr style="cursor:default"><td><span class="down">Contraction</span></td><td class="right num down">−{{ MoneyFormatter::money($waterfall->contraction) }}</td></tr>
                    <tr style="cursor:default"><td><span class="down">Churn</span></td><td class="right num down">−{{ MoneyFormatter::money($waterfall->churn) }}</td></tr>
                    <tr style="cursor:default"><td><span class="up">Reactivation</span></td><td class="right num up">+{{ MoneyFormatter::money($waterfall->reactivation) }}</td></tr>
                    <tr style="cursor:default;font-weight:600"><td>Ending MRR</td><td class="right num">{{ MoneyFormatter::money($waterfall->endMrr) }}</td></tr>
                </tbody>
            </table>
        @else
            <p class="mut" style="padding:24px;text-align:center">No recurring revenue in {{ $currency }} for this window.</p>
        @endif
    </section>

    {{-- ARR bridge — the same decomposition annualised (× 12) --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <h2 class="cbx-panel-title" style="font-size:14px">ARR bridge</h2>
            <span class="cbx-pill cbx-pill--muted">annualised</span>
        </header>
        @if ($arr)
            <table class="tbl">
                <thead><tr><th>Component</th><th class="right" style="width:200px">ARR ({{ $currency }})</th></tr></thead>
                <tbody>
                    <tr style="cursor:default"><td>Starting ARR</td><td class="right num">{{ MoneyFormatter::money($arr->startArr) }}</td></tr>
                    <tr style="cursor:default"><td><span class="up">New</span></td><td class="right num up">+{{ MoneyFormatter::money($arr->new) }}</td></tr>
                    <tr style="cursor:default"><td><span class="up">Expansion</span></td><td class="right num up">+{{ MoneyFormatter::money($arr->expansion) }}</td></tr>
                    <tr style="cursor:default"><td><span class="down">Contraction</span></td><td class="right num down">−{{ MoneyFormatter::money($arr->contraction) }}</td></tr>
                    <tr style="cursor:default"><td><span class="down">Churn</span></td><td class="right num down">−{{ MoneyFormatter::money($arr->churn) }}</td></tr>
                    <tr style="cursor:default"><td><span class="up">Reactivation</span></td><td class="right num up">+{{ MoneyFormatter::money($arr->reactivation) }}</td></tr>
                    <tr style="cursor:default;font-weight:600"><td>Ending ARR</td><td class="right num">{{ MoneyFormatter::money($arr->endArr) }}</td></tr>
                </tbody>
            </table>
        @else
            <p class="mut" style="padding:24px;text-align:center">No ARR to bridge in {{ $currency }}.</p>
        @endif
    </section>
</div>
@endsection
