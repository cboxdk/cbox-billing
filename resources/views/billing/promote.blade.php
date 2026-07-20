@extends('layouts.app')
@section('title', 'Promote config')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Promote'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $targetIsProduction = ($targetKey === \App\Models\Environment::PRODUCTION);
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Promote config</h1>
            <p class="cbx-page-desc" style="font-size:13px">Publish selected configuration from one environment into another — clone production to a sandbox, change what you need, then promote just those parts back. Objects are matched across planes by their stable key; you see the exact diff before anything is written. Promoting into <strong>production</strong> is the go-live action.</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- STEP 1 — choose what to promote and preview the diff. --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">What to promote</h2></header>
        <form method="POST" action="{{ route('billing.environment.promote.preview') }}" style="padding:12px 20px 18px">
            @csrf
            <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
                <label style="{{ $labelStyle }}">Source
                    <select name="source" style="{{ $inputStyle }};min-width:180px">
                        @foreach ($planes as $env)
                            <option value="{{ $env->key }}" @selected($env->key === $sourceKey)>{{ $env->name }}@if(! $env->isProduction()) (sandbox)@endif</option>
                        @endforeach
                    </select>
                </label>
                <span class="mut" style="padding-bottom:8px">→</span>
                <label style="{{ $labelStyle }}">Target
                    <select name="target" style="{{ $inputStyle }};min-width:180px">
                        @foreach ($planes as $env)
                            <option value="{{ $env->key }}" @selected($env->key === $targetKey)>{{ $env->name }}@if($env->isProduction()) (production){{ '' }}@endif</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div style="font-size:12px;font-weight:600;color:var(--muted-foreground);margin-bottom:8px">Groups <span style="font-weight:400">— nothing is promoted unless selected</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;margin-bottom:14px">
                @foreach ($groups as $group)
                    <label style="display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid var(--border);border-radius:8px;cursor:pointer">
                        <input type="checkbox" name="groups[]" value="{{ $group->value }}" @checked(in_array($group->value, $selectedGroups, true)) style="margin-top:2px">
                        <span>
                            <span style="font-size:13px;font-weight:500;display:block">{{ $group->label() }}</span>
                            <span class="mut" style="font-size:11px">{{ $group->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <label style="{{ $labelStyle }};margin-bottom:14px">Individual objects <span class="mut" style="font-weight:400">(optional — one `type:key` per line, e.g. `plan:pro`)</span>
                <textarea name="objects" rows="2" placeholder="plan:pro&#10;coupon:WELCOME" style="{{ $inputStyle }};height:auto;padding:8px;font-family:var(--font-mono, monospace);font-size:12px">{{ $objectsInput }}</textarea>
            </label>

            <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'search', 'size' => 13, 'sw' => 1.8])Preview changes</button>
        </form>
    </section>

    {{-- STEP 2 — the diff preview + confirm. --}}
    @isset($preview)
        <section class="cbx-panel">
            <header class="cbx-panel-header" style="padding:12px 20px;display:flex;align-items:center;justify-content:space-between">
                <h2 class="cbx-panel-title" style="font-size:14px">Diff preview — {{ $preview->source }} → {{ $preview->target }}</h2>
                <div style="display:flex;gap:6px">
                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span>{{ $preview->createdCount() }} created</span>
                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>{{ $preview->updatedCount() }} updated</span>
                    <span class="cbx-pill cbx-pill--muted"><span class="dot"></span>{{ $preview->unchangedCount() }} unchanged</span>
                </div>
            </header>

            <div style="padding:0 20px 12px">
                @if ($preview->hasConflicts())
                    <div class="cbx-panel" style="padding:12px 16px;margin:12px 0;border-left:3px solid var(--destructive)" role="alert">
                        <strong style="color:var(--destructive)">Blocking conflicts.</strong>
                        <span class="mut">Nothing will be promoted until these are resolved — select the missing dependency too, or promote it first.</span>
                        <ul class="mut" style="margin:8px 0 0;padding-left:18px;font-size:12px">
                            @foreach ($preview->conflicts as $conflict)
                                <li><code>{{ $conflict->object }}</code> {{ $conflict->reason }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @forelse ($preview->changes as $change)
                    @include('billing._promote-change', ['change' => $change, 'depth' => 0])
                @empty
                    <div class="cbx-empty" style="padding:24px 0"><h3>Nothing selected.</h3><p>Choose at least one group or object above, then preview.</p></div>
                @endforelse
            </div>

            @if (! $preview->hasConflicts() && $preview->hasWrites())
                <footer class="cbx-panel-header" style="padding:12px 20px;border-top:1px solid var(--border);border-bottom:none">
                    <form method="POST" action="{{ route('billing.environment.promote.apply') }}"
                          data-confirm="This publishes the selected config to “{{ $preview->target }}”{{ $targetIsProduction ? ' — your PRODUCTION plane (real customers)' : '' }}. Matched objects are updated in place; nothing is deleted. Continue?"
                          data-confirm-title="Publish to {{ $preview->target }}?"
                          data-confirm-label="Publish {{ $preview->createdCount() + $preview->updatedCount() }} object(s)"
                          data-confirm-variant="{{ $targetIsProduction ? 'destructive' : 'primary' }}">
                        @csrf
                        <input type="hidden" name="source" value="{{ $preview->source }}">
                        <input type="hidden" name="target" value="{{ $preview->target }}">
                        @foreach ($selectedGroups as $slug)
                            <input type="hidden" name="groups[]" value="{{ $slug }}">
                        @endforeach
                        <input type="hidden" name="objects" value="{{ $objectsInput }}">
                        <button type="submit" class="cbx-btn cbx-btn--{{ $targetIsProduction ? 'destructive' : 'primary' }}">
                            @include('partials.icon', ['name' => 'arrow-up-right', 'size' => 13, 'sw' => 1.8])Publish to {{ $preview->target }}
                        </button>
                        @if ($targetIsProduction)
                            <span class="mut" style="margin-left:10px;font-size:12px">This publishes to production.</span>
                        @endif
                    </form>
                </footer>
            @endif
        </section>
    @endisset
</div>
@endsection
