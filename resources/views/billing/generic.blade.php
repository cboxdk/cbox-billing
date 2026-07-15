@extends('layouts.app')
@section('title', $title)
@section('crumb', $title)

@section('screen')
<div class="page">
    <header class="cbx-page-header"><div><h1 class="cbx-page-title" style="font-size:20px">{{ $title }}</h1></div></header>
    <section class="cbx-panel">
        <div class="cbx-empty">
            <div class="cbx-empty-icon">@include('partials.icon', ['name' => 'box', 'size' => 15, 'sw' => 1.7])</div>
            <div><h3>Nothing here yet</h3><p>This section composes the {{ strtolower($title) }} module of the billing engine. Compose it from the panel, table and filter primitives.</p></div>
            <div style="padding-top:4px"><button class="cbx-btn cbx-btn--secondary cbx-btn--sm">Read the docs</button></div>
        </div>
    </section>
</div>
@endsection
