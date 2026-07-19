@extends('layouts.app')
@section('title', 'Load manifest')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Data'],
        ['label' => 'Warehouse sinks', 'href' => route('billing.exports.warehouse')],
        ['label' => $dataset->label().' manifest'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $sink->warehouseEnum()->label() }} load manifest</h1>
            <p class="cbx-page-desc" style="font-size:13px">The exact statement to load the <strong>{{ $dataset->label() }}</strong> partitions staged by
                <span class="num">{{ $sink->key }}</span> ({{ $dataset->syncMode()->value }} mode). Run it against your warehouse, or wire it into a scheduled loader.
                Replace any <span class="num">&lt;bracketed&gt;</span> placeholder with your load-side value.</p>
        </div>
        <a href="{{ route('billing.exports.warehouse') }}" class="cbx-btn">Back to sinks</a>
    </header>

    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">{{ $dataset->key() }}</h2><p class="cbx-panel-desc" style="font-size:12px">{{ $dataset->description() }}</p></div>
            <span class="cbx-pill cbx-pill--info">{{ $sink->warehouseEnum()->value }}</span>
        </header>
        <div style="padding:16px 20px">
            @if ($manifest === null)
                <p class="mut">This sink stages files only (no warehouse dialect selected). Choose a warehouse on the sink to generate a load manifest.</p>
            @else
                <textarea readonly rows="26" onclick="this.select()" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;line-height:1.5;padding:12px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);resize:vertical">{{ $manifest }}</textarea>
            @endif
        </div>
    </section>
</div>
@endsection
