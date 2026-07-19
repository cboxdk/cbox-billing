@extends('layouts.app')
@section('title', 'Warehouse sinks')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Data'],
        ['label' => 'Warehouse sinks'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Warehouse sinks</h1>
            <p class="cbx-page-desc" style="font-size:13px">Stage dataset partitions to object storage the way Snowflake, BigQuery and Redshift
                actually ingest at scale — partitioned NDJSON/CSV files plus a copy-paste load manifest. A scheduled
                <span class="num">warehouse:sync</span> stages only rows changed since each dataset's watermark; the direct-API push seam is
                left to the deployment (documented in the data-export guide), so nothing here fabricates a warehouse client.</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- Configured sinks --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Configured sinks</h2><p class="cbx-panel-desc" style="font-size:12px">{{ $sinks->count() }} sink(s)</p></div>
        </header>
        @if ($sinks->isEmpty())
            <p class="mut" style="padding:24px;text-align:center">No sinks configured yet. Add one below to start staging datasets.</p>
        @else
            <div style="padding:6px 20px 16px">
                @foreach ($sinks as $sink)
                    <div class="cbx-panel" style="margin-top:12px">
                        <header class="cbx-panel-header" style="padding:10px 16px">
                            <div>
                                <h3 class="cbx-panel-title" style="font-size:13px">{{ $sink->name }}
                                    <span class="cbx-pill {{ $sink->enabled ? 'cbx-pill--success' : 'cbx-pill--muted' }}">{{ $sink->enabled ? 'enabled' : 'disabled' }}</span>
                                    <span class="cbx-pill {{ $sink->livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $sink->livemode ? 'live' : 'test' }}</span>
                                </h3>
                                <p class="cbx-panel-desc num" style="font-size:11px">key {{ $sink->key }} · {{ $sink->warehouseEnum()->label() }} · disk <span class="num">{{ $sink->disk }}</span>/{{ $sink->normalizedPrefix() ?: '(root)' }} · {{ strtoupper($sink->formatEnum()->value) }} · schedule {{ $sink->schedule ?? 'manual' }}</p>
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <form method="POST" action="{{ route('billing.exports.warehouse.run', $sink) }}">@csrf<button class="cbx-btn cbx-btn--sm cbx-btn--primary">Run now</button></form>
                                <form method="POST" action="{{ route('billing.exports.warehouse.toggle', $sink) }}">@csrf<button class="cbx-btn cbx-btn--sm">{{ $sink->enabled ? 'Disable' : 'Enable' }}</button></form>
                                <form method="POST" action="{{ route('billing.exports.warehouse.destroy', $sink) }}" onsubmit="return confirm('Remove this sink and its run history?')">@csrf @method('DELETE')<button class="cbx-btn cbx-btn--sm cbx-btn--destructive">Remove</button></form>
                            </div>
                        </header>
                        <div style="padding:10px 16px">
                            <p class="lbl" style="margin:0 0 6px">Datasets &amp; load manifests</p>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                @foreach ($sink->datasetKeys() as $dsKey)
                                    <a class="cbx-btn cbx-btn--sm cbx-btn--ghost" href="{{ route('billing.exports.warehouse.manifest', ['warehouseSink' => $sink, 'dataset' => $dsKey]) }}">{{ $dsKey }} →</a>
                                @endforeach
                            </div>
                            @php $sinkRuns = $runsBySink->get($sink->id, collect())->take(10); @endphp
                            @if ($sinkRuns->isNotEmpty())
                                <table class="tbl" style="margin-top:10px">
                                    <thead><tr><th>Dataset</th><th>Status</th><th class="right">Rows</th><th class="right">Bytes</th><th>Cursor →</th><th>When</th></tr></thead>
                                    <tbody>
                                        @foreach ($sinkRuns as $run)
                                            <tr style="cursor:default">
                                                <td class="num">{{ $run->dataset }}</td>
                                                <td><span class="cbx-pill {{ $run->status === 'failed' ? 'cbx-pill--destructive' : ($run->status === 'empty' ? 'cbx-pill--muted' : 'cbx-pill--success') }}">{{ $run->status }}</span></td>
                                                <td class="right num">{{ number_format((int) $run->rows) }}</td>
                                                <td class="right num">{{ number_format((int) $run->bytes) }}</td>
                                                <td class="mut num" style="font-size:11px">{{ $run->cursor_to ?? '—' }}</td>
                                                <td class="mut">{{ $run->created_at?->diffForHumans() }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Add a sink --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Add a sink</h2><p class="cbx-panel-desc" style="font-size:12px">Stage to any configured filesystem disk (use <span class="num">s3</span> for a real deployment).</p></div>
        </header>
        <form method="POST" action="{{ route('billing.exports.warehouse.store') }}" style="padding:16px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
            @csrf
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Key</span><input name="key" required placeholder="analytics-s3" pattern="[a-z0-9_-]+" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Name</span><input name="name" required placeholder="Analytics warehouse" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Disk</span><input name="disk" required value="s3" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Prefix</span><input name="prefix" placeholder="billing/export" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Format</span>
                <select name="format" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    @foreach ($formats as $f)<option value="{{ $f->value }}">{{ strtoupper($f->value) }}</option>@endforeach
                </select>
            </label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Warehouse</span>
                <select name="warehouse" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    @foreach ($warehouses as $w)<option value="{{ $w->value }}">{{ $w->label() }}</option>@endforeach
                </select>
            </label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Plane</span>
                <select name="livemode" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    <option value="1">Live</option>
                    <option value="0">Test / sandbox</option>
                </select>
            </label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Schedule (cron, optional)</span><input name="schedule" placeholder="0 * * * *" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">External base URI</span><input name="external_base" placeholder="s3://your-bucket/billing/export" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Target schema</span><input name="target_schema" placeholder="analytics_billing" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Stage / storage integration</span><input name="target_stage" placeholder="BILLING_STAGE" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <label style="display:block"><span class="lbl" style="display:block;margin-bottom:4px">Credential (IAM role, optional)</span><input name="credential" placeholder="arn:aws:iam::…:role/redshift-load" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)"></label>
            <fieldset style="grid-column:1/-1;border:1px solid var(--border);border-radius:8px;padding:10px 12px">
                <legend class="lbl" style="padding:0 6px">Datasets</legend>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    @foreach ($datasetOptions as $d)
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px"><input type="checkbox" name="datasets[]" value="{{ $d['key'] }}">{{ $d['label'] }}</label>
                    @endforeach
                </div>
            </fieldset>
            <div style="grid-column:1/-1"><button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7])Create sink</button></div>
        </form>
    </section>
</div>
@endsection
