@extends('layouts.app')
@section('title', 'Review change')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Subscriptions', 'href' => route('billing.subscriptions')],
        ['label' => $subscription['org'] ?? 'Subscription', 'href' => route('billing.subscriptions.show', $subscription['id'])],
        ['label' => 'Review'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <a class="cbx-btn cbx-btn--ghost cbx-btn--sm" href="{{ route('billing.subscriptions.show', $subscription['id']) }}" style="align-self:flex-start">@include('partials.icon', ['name' => 'chevron-right', 'size' => 14, 'sw' => 1.7]) Back to subscription</a>

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $title }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $description }}</p>
        </div>
    </header>

    <section class="cbx-panel" style="max-width:520px">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Preview</h2></header>
        <dl style="margin:0;padding:2px 20px 6px">
            @foreach ($stats as $stat)
                <div class="cbx-kv" style="padding:9px 0"><dt>{{ $stat['label'] }}</dt><dd class="num">{{ $stat['value'] }}</dd></div>
            @endforeach
        </dl>
        <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px">
            <form method="POST" action="{{ $confirm }}">
                @csrf
                @foreach ($hidden as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">{{ $confirmLabel }}</button>
            </form>
            <a href="{{ route('billing.subscriptions.show', $subscription['id']) }}" class="cbx-btn cbx-btn--sm">Cancel</a>
        </div>
    </section>
    <p class="mut" style="font-size:12px;max-width:520px">This is the exact consequence the engine will apply — the preview equals the charge.</p>
</div>
@endsection
