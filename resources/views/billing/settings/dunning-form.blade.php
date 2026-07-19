@extends('layouts.app')
@section('title', 'Edit retry strategy')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Retry strategy', 'href' => route('billing.settings.dunning')],
        ['label' => $label],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
    $checkStyle = 'display:flex;gap:8px;align-items:center;font-size:13px;font-weight:500';
    $backoff = old('backoff_days', implode(', ', $plan->backoffDays));
@endphp

@section('screen')
<div class="page" style="max-width:640px">
    <x-back-button :href="route('billing.settings.dunning')" label="Back to retry strategy" />

    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $label }} strategy</h1>
            <p class="cbx-page-desc" style="font-size:13px">{{ $description }}</p>
        </div>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ route('billing.settings.dunning.update', $category) }}" style="padding:18px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @method('PUT')

            <label style="{{ $labelStyle }}">Backoff (day-offsets from the first failure)
                <input type="text" name="backoff_days" value="{{ $backoff }}" placeholder="2, 5, 9, 14" required style="{{ $inputStyle }}">
                <span class="mut" style="font-weight:400">Comma-separated positive days. One entry per attempt; the count is the retry ceiling unless capped below.</span>
            </label>

            <label style="{{ $labelStyle }}">Max attempts (optional cap)
                <input type="number" name="max_attempts" value="{{ old('max_attempts', $plan->maxAttempts) }}" min="1" max="20" style="{{ $inputStyle }};width:120px">
                <span class="mut" style="font-weight:400">Leave to use the number of backoff entries.</span>
            </label>

            <label style="{{ $checkStyle }}">
                <input type="checkbox" name="avoid_weekends" value="1" @checked(old('avoid_weekends', $plan->avoidWeekends))>
                Avoid weekends — push an attempt landing on a quiet weekday to the next weekday
            </label>

            <label style="{{ $checkStyle }}">
                <input type="checkbox" name="align_to_payday" value="1" @checked(old('align_to_payday', $plan->alignToPayday))>
                Align to payday — pull an attempt forward to the next payday anchor
            </label>

            <label style="{{ $checkStyle }}">
                <input type="checkbox" name="retry" value="1" @checked(old('retry', $plan->retry))>
                Retry this category
            </label>

            <div style="display:flex;gap:8px;align-items:center;padding-top:4px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 2]) Save strategy</button>
                @if ($overridden)
                    <span style="margin-left:auto"></span>
                @endif
            </div>
        </form>

        @if ($overridden)
            <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
                <span class="mut" style="font-size:12px">This category has a custom override.</span>
                <form method="POST" action="{{ route('billing.settings.dunning.reset', $category) }}"
                      data-confirm="Revert {{ $label }} to the shipped defaults?" data-confirm-title="Revert to defaults?" data-confirm-label="Revert" data-confirm-variant="destructive">
                    @csrf
                    <button type="submit" class="cbx-btn cbx-btn--sm" style="color:var(--destructive)">Revert to defaults</button>
                </form>
            </div>
        @endif
    </section>
</div>
@endsection
