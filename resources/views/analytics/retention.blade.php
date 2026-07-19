@extends('layouts.app')
@section('title', 'Retention analytics')
@section('crumb', 'Analytics')

@php
    use App\Billing\Support\MoneyFormatter;

    $bps = fn ($ratio) => $ratio && $ratio->isDefined() ? number_format($ratio->basisPoints() / 100, 1, ',', '.').'%' : '—';
    $nrr = $rates?->nrr;
    $grr = $rates?->grr;
    $churnPct = number_format($churnRate * 100, 2, ',', '.');

    // Retention heat cell: retained MRR as a fraction of the cohort's age-0 MRR.
    $cellPct = function ($cell, $row): ?int {
        $base = $row->initialMrr->minor();
        return $base > 0 ? (int) round($cell->retainedMrr->minor() / $base * 100) : null;
    };
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Retention analytics</h1>
            <p class="cbx-page-desc" style="font-size:13px">Net &amp; gross revenue retention · cohort survival · {{ $windowStart }} → {{ $windowEnd }}</p>
        </div>
        <div class="filters" style="margin:0">
            @foreach ($currencies as $cur)
                <a class="fchip {{ $currency === $cur ? 'set' : '' }}" href="{{ route('analytics.retention', ['currency' => $cur]) }}">{{ $cur }}</a>
            @endforeach
        </div>
    </header>

    {{-- NRR / GRR — computed by the engine RetentionCalculator from the movement waterfall --}}
    <div class="stats">
        <div>
            <p class="lbl">Net revenue retention</p>
            <p class="val">{{ $bps($nrr) }}</p>
            <span class="delta {{ $nrr && $nrr->basisPoints() >= 10000 ? 'up' : 'warn' }} num">start + expansion − contraction − churn</span>
        </div>
        <div>
            <p class="lbl">Gross revenue retention</p>
            <p class="val">{{ $bps($grr) }}</p>
            <span class="delta mut num">start − contraction − churn</span>
        </div>
        <div>
            <p class="lbl">Logo churn</p>
            <p class="val">{{ $churnPct }}%</p>
            <span class="delta mut num">customers lost · this window</span>
        </div>
        <div>
            <p class="lbl">Retained MRR</p>
            <p class="val">{{ $waterfall ? MoneyFormatter::money($waterfall->startMrr->minus($waterfall->churn)->minus($waterfall->contraction)) : '—' }}</p>
            <span class="delta mut num">of {{ $waterfall ? MoneyFormatter::money($waterfall->startMrr) : '—' }} starting</span>
        </div>
    </div>

    {{-- Cohort retention matrix — engine CohortRetention: retained MRR by cohort × age --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Cohort retention</h2><p class="cbx-panel-desc" style="font-size:12px">retained MRR as a share of each cohort's starting MRR</p></div>
            <span class="cbx-pill cbx-pill--info">{{ $currency }}</span>
        </header>
        @if (count($cohorts->rows) > 0)
            <div style="overflow-x:auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width:110px">Cohort</th>
                            <th class="right" style="width:80px">Accounts</th>
                            <th class="right" style="width:120px">Start MRR</th>
                            @for ($age = 0; $age < count($cohorts->periods); $age++)
                                <th class="right">m{{ $age }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cohorts->rows as $row)
                            <tr style="cursor:default">
                                <td class="num" style="font-weight:600">{{ $row->cohort }}</td>
                                <td class="right num">{{ $row->initialCount }}</td>
                                <td class="right num">{{ MoneyFormatter::money($row->initialMrr) }}</td>
                                @for ($age = 0; $age < count($cohorts->periods); $age++)
                                    @php($cell = $row->cellAtAge($age))
                                    @if ($cell === null)
                                        <td class="right"></td>
                                    @else
                                        @php($pct = $cellPct($cell, $row))
                                        <td class="right num" @if($pct !== null)style="background:color-mix(in oklch, var(--success) {{ max(0, min(100, $pct)) * 0.32 }}%, transparent)"@endif title="{{ $cell->retainedCount }} accounts · {{ MoneyFormatter::money($cell->retainedMrr) }}">
                                            @if($pct === null)—@else{{ $pct }}%@endif
                                        </td>
                                    @endif
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mut" style="padding:24px;text-align:center">No cohorts started in {{ $currency }} over the last {{ count($cohorts->periods) }} months.</p>
        @endif
    </section>
</div>
@endsection
