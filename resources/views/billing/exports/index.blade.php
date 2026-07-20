@extends('layouts.app')
@section('title', 'Data exports')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Data'],
        ['label' => 'Exports'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Data exports</h1>
            <p class="cbx-page-desc" style="font-size:13px">Stream any dataset to CSV or newline-delimited JSON, scoped to the current plane
                (<span class="cbx-pill {{ $livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $livemode ? 'live' : 'test' }}</span>)
                and an optional date range. Every export streams straight from the database — the whole dataset is never held in memory.</p>
        </div>
        <a href="{{ route('billing.exports.warehouse') }}" class="cbx-btn">@include('partials.icon', ['name' => 'box', 'size' => 14, 'sw' => 1.7])Warehouse sinks</a>
    </header>

    @include('partials.flash')

    {{-- Build + download an export --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Download an export</h2><p class="cbx-panel-desc" style="font-size:12px">Pick a dataset, a format and an optional inclusive date range.</p></div>
        </header>
        <form method="GET" action="{{ route('billing.exports.download') }}" style="padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;align-items:end">
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Dataset</span>
                <select name="dataset" required style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    @foreach ($datasets as $d)
                        <option value="{{ $d['key'] }}">{{ $d['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Format</span>
                <select name="format" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    @foreach ($formats as $f)
                        <option value="{{ $f }}">{{ strtoupper($f) }}</option>
                    @endforeach
                </select>
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">From (optional)</span>
                <input type="date" name="from" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">To (optional)</span>
                <input type="date" name="to" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
            </label>
            <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 14, 'sw' => 1.7])Download</button>
        </form>
    </section>

    {{-- The dataset catalog with quick download links --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Datasets</h2><p class="cbx-panel-desc" style="font-size:12px">{{ count($datasets) }} datasets · each a stable, typed, documented schema</p></div>
        </header>
        <table class="tbl">
            <thead><tr><th>Dataset</th><th>Load mode</th><th class="right">Columns</th><th>Date axis</th><th class="right">Download</th></tr></thead>
            <tbody>
                @foreach ($datasets as $d)
                    <tr style="cursor:default">
                        <td><strong>{{ $d['label'] }}</strong><br><span class="mut" style="font-size:12px">{{ $d['description'] }}</span></td>
                        <td><span class="cbx-pill cbx-pill--muted">{{ $d['sync_mode'] }}</span></td>
                        <td class="right num">{{ $d['columns'] }}</td>
                        <td class="mut num">{{ $d['date_column'] ?? '—' }}</td>
                        <td class="right" style="white-space:nowrap">
                            <a class="cbx-btn cbx-btn--sm" href="{{ route('billing.exports.download', ['dataset' => $d['key'], 'format' => 'csv']) }}">CSV</a>
                            <a class="cbx-btn cbx-btn--sm" href="{{ route('billing.exports.download', ['dataset' => $d['key'], 'format' => 'ndjson']) }}">NDJSON</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Recent warehouse sync runs --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Recent warehouse sync runs</h2><p class="cbx-panel-desc" style="font-size:12px">The delivery log from scheduled + on-demand warehouse syncs</p></div>
        </header>
        @if ($runs->isEmpty())
            <div class="cbx-empty">
                <div class="cbx-empty-icon">@include('partials.icon', ['name' => 'box', 'size' => 18, 'sw' => 1.7])</div>
                <h3>No sync runs yet</h3>
                <p>Configure a <a href="{{ route('billing.exports.warehouse') }}" class="cbx-link">warehouse sink</a> to start delivering datasets on a schedule.</p>
            </div>
        @else
            <table class="tbl">
                <thead><tr><th>Dataset</th><th>Status</th><th class="right">Rows</th><th class="right">Bytes</th><th>Partition</th><th>When</th></tr></thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr style="cursor:default">
                            <td class="num">{{ $run->dataset }}</td>
                            <td><span class="cbx-pill {{ $run->status === 'failed' ? 'cbx-pill--destructive' : ($run->status === 'empty' ? 'cbx-pill--muted' : 'cbx-pill--success') }}">{{ $run->status }}</span></td>
                            <td class="right num">{{ number_format((int) $run->rows) }}</td>
                            <td class="right num">{{ number_format((int) $run->bytes) }}</td>
                            <td class="mut num" style="font-size:11px">{{ $run->partition_path ?? '—' }}</td>
                            <td class="mut">{{ $run->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
@endsection
