@php
    use App\Billing\Import\Enums\ImportEntityType;
    use App\Billing\Import\Enums\ImportOutcome;

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
@section('title', 'Import dry-run')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Data'],
        ['label' => 'Import', 'url' => route('billing.import')],
        ['label' => 'Dry-run'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Dry-run · {{ $source->label() }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">Nothing has been written yet. Review the plan below, adjust the plan mapping if a source plan should point at an existing
                app plan, then commit. Re-running the same export later is a no-op on rows already imported.</p>
        </div>
        <span class="cbx-pill {{ $run->livemode ? 'cbx-pill--success' : 'cbx-pill--warning' }}">{{ $run->livemode ? 'live' : 'test' }}</span>
    </header>

    @include('partials.flash')

    {{-- Counts summary --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px">
            <div><h2 class="cbx-panel-title" style="font-size:14px">What would happen</h2><p class="cbx-panel-desc" style="font-size:12px">Per entity: how many records would be created, updated, skipped (already imported) or flagged.</p></div>
        </header>
        <table class="tbl">
            <thead><tr><th>Entity</th><th class="right">Created</th><th class="right">Updated</th><th class="right">Skipped</th><th class="right">Conflicts</th><th class="right">Failed</th></tr></thead>
            <tbody>
                @foreach ($plan->counts() as $entity => $byOutcome)
                    @php $sum = array_sum($byOutcome); @endphp
                    @if ($sum > 0)
                        <tr style="cursor:default">
                            <td><strong>{{ ImportEntityType::from($entity)->label() }}</strong></td>
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

    {{-- Conflicts --}}
    @if ($plan->hasConflicts())
        <section class="cbx-panel" style="border-color:var(--warning,#b7791f)">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">Conflicts to resolve ({{ count($plan->conflicts()) }})</h2><p class="cbx-panel-desc" style="font-size:12px">These rows are <strong>not</strong> imported — resolve them (map a plan, dedupe an email, fix a currency/interval) and re-run. Other rows still commit.</p></div>
            </header>
            <table class="tbl">
                <thead><tr><th>Entity</th><th>Source</th><th>Reason</th></tr></thead>
                <tbody>
                    @foreach ($plan->conflicts() as $c)
                        <tr style="cursor:default">
                            <td>{{ $c->entity->label() }}</td>
                            <td class="num">{{ $c->sourceLabel }}</td>
                            <td class="mut">{{ $c->message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <form method="POST" action="{{ route('billing.import.commit', $run->id) }}">
        @csrf

        {{-- Plan mapping editor --}}
        @if (count($sourcePlans) > 0)
            <section class="cbx-panel">
                <header class="cbx-panel-header" style="padding:12px 20px">
                    <div><h2 class="cbx-panel-title" style="font-size:14px">Plan mapping</h2><p class="cbx-panel-desc" style="font-size:12px">Leave a source plan on <em>auto</em> to import it (matched by key, else created), or route it to an existing app plan.</p></div>
                </header>
                <table class="tbl">
                    <thead><tr><th>Source plan</th><th>Interval</th><th>App plan</th></tr></thead>
                    <tbody>
                        @foreach ($sourcePlans as $sp)
                            <tr style="cursor:default">
                                <td><strong>{{ $sp->name }}</strong><br><span class="mut num" style="font-size:12px">{{ $sp->sourceId }}</span></td>
                                <td>@if ($sp->interval)<span class="cbx-pill cbx-pill--muted">{{ $sp->interval->value }}</span>@else<span class="cbx-pill cbx-pill--warning">{{ $sp->rawInterval ?: 'unknown' }}</span>@endif</td>
                                <td>
                                    <select name="mapping[{{ $sp->sourceId }}]" style="padding:6px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);min-width:220px">
                                        <option value="">— auto (import / match by key) —</option>
                                        @foreach ($appPlans as $ap)
                                            <option value="{{ $ap->id }}">{{ $ap->name }} ({{ $ap->key }})</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        {{-- Planned actions detail --}}
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px">
                <div><h2 class="cbx-panel-title" style="font-size:14px">Planned actions</h2><p class="cbx-panel-desc" style="font-size:12px">Every source record and what would happen to it.</p></div>
            </header>
            <div style="padding:8px 20px 12px">
                @foreach (ImportEntityType::ordered() as $entity)
                    @php $actions = $plan->forEntity($entity); @endphp
                    @if (count($actions) > 0)
                        <details style="padding:6px 0;border-bottom:1px solid var(--border)">
                            <summary style="cursor:pointer;font-weight:600">{{ $entity->label() }} ({{ count($actions) }})</summary>
                            <table class="tbl" style="margin-top:6px">
                                <thead><tr><th>Source</th><th>Outcome</th><th>Detail</th></tr></thead>
                                <tbody>
                                    @foreach ($actions as $a)
                                        <tr style="cursor:default">
                                            <td class="num">{{ $a->sourceLabel }}</td>
                                            <td><span class="cbx-pill {{ $pill($a->outcome->value) }}">{{ $a->outcome->value }}</span></td>
                                            <td class="mut">{{ $a->message }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif
                @endforeach
            </div>
        </section>

        <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
            <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'arrow-up-right', 'size' => 14, 'sw' => 1.7])Commit import</button>
            <a href="{{ route('billing.import') }}" class="cbx-btn">Cancel</a>
            @if ($plan->hasConflicts())
                <span class="mut" style="font-size:12px">Conflicted rows will be skipped.</span>
            @endif
        </div>
    </form>
</div>
@endsection
