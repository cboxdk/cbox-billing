@extends('layouts.app')
@section('title', 'Import & migration')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Data'],
        ['label' => 'Import'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Import &amp; migration</h1>
            <p class="cbx-page-desc" style="font-size:13px">Bring a seller's catalog, customers, subscriptions and historical invoices over from another
                provider by uploading their data export. You get a <strong>dry-run report</strong> — what would be created, updated or skipped, plus any
                conflicts to resolve and the proposed plan mapping to adjust — <strong>before</strong> anything is written. Imports are idempotent (re-running the
                same file changes nothing) and land in the current plane
                (<span class="cbx-pill {{ $livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $livemode ? 'live' : 'test' }}</span> —
                import into test mode first to validate).</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- Upload + dry-run --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Start an import</h2><p class="cbx-panel-desc" style="font-size:12px">Pick a source and upload its export file(s), or paste the combined JSON export. Nothing is written until you review + commit.</p></div>
        </header>
        <form method="POST" action="{{ route('billing.import.preview') }}" enctype="multipart/form-data" style="padding:16px 20px;display:grid;gap:16px">
            @csrf
            <label style="display:block;max-width:280px">
                <span class="lbl" style="display:block;margin-bottom:4px">Source</span>
                <select name="source" required style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                    @foreach ($sources as $s)
                        <option value="{{ $s['value'] }}">{{ $s['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">Export file(s)</span>
                <input type="file" name="files[]" multiple accept=".json,application/json" style="width:100%;padding:8px;border:1px dashed var(--border);border-radius:8px;background:var(--card);color:var(--foreground)">
                <span class="mut" style="font-size:12px">Upload one file per resource (the file name is the resource — e.g. <code>customers.json</code>, <code>prices.json</code>), or a single combined JSON whose top-level keys are the resource names.</span>
            </label>

            <label style="display:block">
                <span class="lbl" style="display:block;margin-bottom:4px">…or paste the combined JSON export</span>
                <textarea name="payload" rows="4" placeholder='{ "customers": [ … ], "prices": [ … ], "subscriptions": [ … ] }' style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);font-family:ui-monospace,monospace;font-size:12px">{{ old('payload') }}</textarea>
            </label>

            <div>
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 14, 'sw' => 1.7])Dry-run</button>
            </div>
        </form>
    </section>

    {{-- Which files each source provides --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Supported sources &amp; export files</h2><p class="cbx-panel-desc" style="font-size:12px">Each adapter maps that provider's field names and unit convention into the app's model.</p></div>
        </header>
        <div style="padding:8px 20px 16px">
            @foreach ($sources as $s)
                <details style="padding:8px 0;border-bottom:1px solid var(--border)">
                    <summary style="cursor:pointer;font-weight:600">{{ $s['label'] }}</summary>
                    <table class="tbl" style="margin-top:8px">
                        <thead><tr><th>Resource</th><th>Provides</th></tr></thead>
                        <tbody>
                            @foreach ($s['files'] as $resource => $desc)
                                <tr style="cursor:default"><td class="num"><code>{{ $resource }}</code></td><td class="mut">{{ $desc }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endforeach
        </div>
    </section>

    {{-- Recent runs --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">Recent runs</h2><p class="cbx-panel-desc" style="font-size:12px">The last 25 import runs in this plane.</p></div>
        </header>
        <table class="tbl">
            <thead><tr><th>#</th><th>Source</th><th>Status</th><th>Plane</th><th>When</th><th class="right"></th></tr></thead>
            <tbody>
                @forelse ($runs as $run)
                    <tr style="cursor:default">
                        <td class="num">{{ $run->id }}</td>
                        <td>{{ ucfirst($run->source) }}</td>
                        <td>
                            @if ($run->isCommitted())
                                <span class="cbx-pill cbx-pill--success">committed</span>
                            @elseif ($run->status === 'running')
                                <span class="cbx-pill cbx-pill--warning">running</span>
                            @elseif ($run->status === 'failed')
                                <span class="cbx-pill cbx-pill--danger">failed</span>
                            @else
                                <span class="cbx-pill cbx-pill--muted">planned (dry-run)</span>
                            @endif
                        </td>
                        <td><span class="cbx-pill {{ $run->livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $run->livemode ? 'live' : 'test' }}</span></td>
                        <td class="mut">{{ $run->created_at?->diffForHumans() }}</td>
                        <td class="right"><a class="cbx-btn cbx-btn--sm" href="{{ route('billing.import.runs.show', $run->id) }}">Log</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:0"><div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'box', 'size' => 18, 'sw' => 1.7])</div><h3>No imports yet</h3><p>Start a dry-run above to preview what a source export would import, then commit it.</p></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
