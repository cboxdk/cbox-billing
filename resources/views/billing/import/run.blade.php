@php
    use App\Billing\Import\Enums\ImportEntityType;

    $pill = fn (string $outcome): string => match ($outcome) {
        'created' => 'cbx-pill--success',
        'updated' => 'cbx-pill--info',
        'skipped' => 'cbx-pill--muted',
        'conflict' => 'cbx-pill--warning',
        'failed' => 'cbx-pill--danger',
        default => 'cbx-pill--muted',
    };
@endphp
@extends('layouts.app')
@section('title', 'Import run #' . $run->id)
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Data'],
        ['label' => 'Import', 'href' => route('billing.import')],
        ['label' => 'Run #' . $run->id],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Import run #{{ $run->id }} · {{ ucfirst($run->source) }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">
                @if ($run->isCommitted())
                    Committed {{ $run->committed_at?->diffForHumans() }} by {{ $run->actor_name ?? 'system' }}.
                @else
                    Status: {{ $run->status }}.
                @endif
                The source→app id mapping below is the durable, browsable record of what this run imported.
            </p>
        </div>
        <span class="cbx-pill {{ $run->livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $run->livemode ? 'live' : 'test' }}</span>
    </header>

    @include('partials.flash')

    {{-- Counts summary --}}
    @if ($run->counts)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">Summary</h2></div>
            </header>
            <table class="tbl">
                <thead><tr><th>Entity</th><th class="right">Created</th><th class="right">Updated</th><th class="right">Skipped</th><th class="right">Conflicts</th><th class="right">Failed</th></tr></thead>
                <tbody>
                    @foreach ($run->counts as $entity => $byOutcome)
                        @php $sum = array_sum($byOutcome); @endphp
                        @if ($sum > 0)
                            <tr style="cursor:default">
                                <td><strong>{{ ImportEntityType::tryFrom($entity)?->label() ?? $entity }}</strong></td>
                                <td class="right num">{{ $byOutcome['created'] ?? 0 }}</td>
                                <td class="right num">{{ $byOutcome['updated'] ?? 0 }}</td>
                                <td class="right num mut">{{ $byOutcome['skipped'] ?? 0 }}</td>
                                <td class="right num">{{ $byOutcome['conflict'] ?? 0 }}</td>
                                <td class="right num">{{ $byOutcome['failed'] ?? 0 }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    {{-- Per-entity log --}}
    @forelse ($entries as $type => $rows)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">{{ ImportEntityType::tryFrom($type)?->label() ?? ucfirst($type) }} ({{ count($rows) }})</h2></div>
            </header>
            <table class="tbl">
                <thead><tr><th>Source id</th><th>Outcome</th><th>App record</th><th>Detail</th></tr></thead>
                <tbody>
                    @foreach ($rows as $entry)
                        <tr style="cursor:default">
                            <td class="num">{{ $entry->source_id }}</td>
                            <td><span class="cbx-pill {{ $pill($entry->outcome) }}">{{ $entry->outcome }}</span></td>
                            <td class="num mut">{{ $entry->app_type ? $entry->app_type . ' #' . $entry->app_id : '—' }}</td>
                            <td class="mut">{{ $entry->message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <section class="cbx-panel"><div style="padding:20px" class="mut">No log entries yet — a queued commit fills this in as it processes.</div></section>
    @endforelse
</div>
@endsection
