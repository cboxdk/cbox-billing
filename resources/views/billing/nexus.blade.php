@extends('layouts.app')
@section('title', 'US economic nexus')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Nexus'],
        ['label' => 'US economic nexus'],
    ]" />
@endsection

@php
    use App\Billing\Nexus\UsStates;

    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';

    $statusPill = [
        'triggered' => 'danger',
        'approaching' => 'warning',
        'registered' => 'success',
        'below' => 'neutral',
    ];
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">US economic nexus</h1>
            <p class="cbx-page-desc" style="font-size:13px">Where your default selling entity has — or is approaching — a US sales-tax registration obligation. Thresholds come from the us-tax-data dataset; sales from your invoices (every currency, valued in USD) plus any external-channel sales you record below. <strong>Triggered</strong> = register now; <strong>Approaching</strong> = watch.</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="cbx-pill cbx-pill--danger">{{ $triggeredCount }} triggered</span>
            <span class="cbx-pill cbx-pill--warning">{{ $approachingCount }} approaching</span>
            <span class="cbx-pill cbx-pill--success">{{ $registeredCount }} registered</span>
        </div>
    </header>

    @include('partials.flash')

    @unless ($soleSalesChannel)
        <div class="cbx-panel" style="border-left:3px solid var(--warning, #b45309);padding:12px 16px;font-size:12.5px;line-height:1.5">
            <strong>Multi-channel:</strong> the sales below reflect only invoices issued through this platform plus the external-channel sales you record. If you sell through channels you have not recorded, those also count toward each state's threshold — a state shown <em>Below</em> or <em>Approaching</em> may already be <em>Triggered</em> once every channel is combined. Set <code>CBOX_NEXUS_SOLE_SALES_CHANNEL=true</code> if this platform is your only US sales channel.
        </div>
    @endunless

    {{-- Per-state standing --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Standing by state</h2></header>
        <table class="tbl">
            <thead><tr>
                <th style="width:180px">State</th>
                <th style="width:110px">Status</th>
                <th>Threshold</th>
                <th style="width:100px">Progress</th>
                <th style="width:150px">Basis</th>
            </tr></thead>
            <tbody>
                @forelse ($evaluations as $e)
                    <tr>
                        <td style="font-weight:500">{{ UsStates::name($e->state->value) }} <span class="num mut" style="font-size:11px">{{ $e->state->value }}</span></td>
                        <td><span class="cbx-pill cbx-pill--{{ $statusPill[$e->status->value] ?? 'neutral' }}">{{ ucfirst($e->status->value) }}</span></td>
                        <td class="mut" style="font-size:12.5px">{{ $e->threshold?->describe() ?? '—' }}</td>
                        <td class="num">{{ $e->progress !== null ? number_format($e->progress * 100, 1).'%' : '—' }}</td>
                        <td style="font-size:11.5px">
                            @if ($e->physicalPresence)<span class="cbx-pill cbx-pill--neutral">presence</span>@endif
                            @if (in_array($e->state->value, $registeredStates, true))<span class="cbx-pill cbx-pill--success">registered</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:0">
                        <div class="cbx-empty"><div class="cbx-empty-icon">@include('partials.icon', ['name' => 'shield', 'size' => 18, 'sw' => 1.7])</div><h3>No exposure yet.</h3><p>As you sell into US states — or declare presence / external sales below — each state's standing appears here.</p></div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mut" style="padding:10px 20px;font-size:11.5px">Registrations are managed on each seller entity under <a href="{{ route('billing.settings', ['tab' => 'sellers']) }}">Settings → Sellers</a>; a registered state reports as handled.</p>
    </section>

    {{-- Physical presence register --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">Physical presence</h2></header>
        <p class="mut" style="padding:0 20px;font-size:12px">A state where you have an office, employees, or inventory (incl. FBA) — a nexus trigger on its own, regardless of sales. An optional window covers a presence you opened and later closed.</p>
        <table class="tbl">
            <thead><tr><th>State</th><th style="width:140px">From</th><th style="width:140px">To</th><th style="width:80px"></th></tr></thead>
            <tbody>
                @forelse ($presence as $p)
                    <tr>
                        <td style="font-weight:500">{{ UsStates::name($p->subdivision) }} <span class="num mut" style="font-size:11px">{{ $p->subdivision }}</span></td>
                        <td class="num mut">{{ $p->effective_from?->format('Y-m-d') ?? 'always' }}</td>
                        <td class="num mut">{{ $p->effective_to?->format('Y-m-d') ?? 'ongoing' }}</td>
                        <td>
                            <form method="POST" action="{{ route('billing.nexus.presence.destroy', $p->id) }}" onsubmit="return confirm('Remove physical presence in {{ $p->subdivision }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="mut" style="padding:14px 20px;font-size:12.5px">No physical presence declared.</td></tr>
                @endforelse
            </tbody>
        </table>
        <form method="POST" action="{{ route('billing.nexus.presence.store') }}" style="padding:12px 20px;display:flex;gap:12px;align-items:end;flex-wrap:wrap">
            @csrf
            <label style="{{ $labelStyle }}">State
                <select name="subdivision" required style="{{ $inputStyle }};min-width:200px">
                    <option value="">Select a state…</option>
                    @foreach ($states as $code => $name)<option value="{{ $code }}" @selected(old('subdivision') === $code)>{{ $name }} ({{ $code }})</option>@endforeach
                </select>
            </label>
            <label style="{{ $labelStyle }}">From <span class="mut" style="font-weight:400">(optional)</span>
                <input type="date" name="effective_from" value="{{ old('effective_from') }}" style="{{ $inputStyle }}">
            </label>
            <label style="{{ $labelStyle }}">To <span class="mut" style="font-weight:400">(optional)</span>
                <input type="date" name="effective_to" value="{{ old('effective_to') }}" style="{{ $inputStyle }}">
            </label>
            <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Add presence</button>
        </form>
    </section>

    {{-- External-channel sales register --}}
    <section class="cbx-panel">
        <header class="cbx-panel-header" style="padding:12px 20px"><h2 class="cbx-panel-title" style="font-size:14px">External-channel sales</h2></header>
        <p class="mut" style="padding:0 20px;font-size:12px">Sales into a state through channels this platform does not invoice — a marketplace (Amazon/eBay), another storefront or billing system. Enter whole USD figures reconciled from each channel's own reports, per calendar year; they are added to your invoiced sales toward each state's threshold.</p>
        <table class="tbl">
            <thead><tr><th>State</th><th style="width:90px">Year</th><th style="width:140px">Sales (USD)</th><th style="width:120px">Transactions</th><th>Source</th><th style="width:80px"></th></tr></thead>
            <tbody>
                @forelse ($externalSales as $x)
                    <tr>
                        <td style="font-weight:500">{{ UsStates::name($x->subdivision) }} <span class="num mut" style="font-size:11px">{{ $x->subdivision }}</span></td>
                        <td class="num">{{ $x->period_year }}</td>
                        <td class="num">${{ number_format($x->sales_dollars) }}</td>
                        <td class="num">{{ number_format($x->transactions) }}</td>
                        <td class="mut">{{ $x->source ?? '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('billing.nexus.external-sales.destroy', $x->id) }}" onsubmit="return confirm('Remove this external-sales entry?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="cbx-btn cbx-btn--ghost cbx-btn--sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="mut" style="padding:14px 20px;font-size:12.5px">No external-channel sales recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
        <form method="POST" action="{{ route('billing.nexus.external-sales.store') }}" style="padding:12px 20px;display:flex;gap:12px;align-items:end;flex-wrap:wrap">
            @csrf
            <label style="{{ $labelStyle }}">State
                <select name="subdivision" required style="{{ $inputStyle }};min-width:180px">
                    <option value="">Select a state…</option>
                    @foreach ($states as $code => $name)<option value="{{ $code }}" @selected(old('subdivision') === $code)>{{ $name }} ({{ $code }})</option>@endforeach
                </select>
            </label>
            <label style="{{ $labelStyle }}">Year
                <input type="number" name="period_year" value="{{ old('period_year', $currentYear) }}" min="2000" max="2100" required style="{{ $inputStyle }};width:90px">
            </label>
            <label style="{{ $labelStyle }}">Sales (USD)
                <input type="number" name="sales_dollars" value="{{ old('sales_dollars') }}" min="0" required style="{{ $inputStyle }};width:140px">
            </label>
            <label style="{{ $labelStyle }}">Transactions
                <input type="number" name="transactions" value="{{ old('transactions') }}" min="0" required style="{{ $inputStyle }};width:120px">
            </label>
            <label style="{{ $labelStyle }}">Source <span class="mut" style="font-weight:400">(optional)</span>
                <input type="text" name="source" value="{{ old('source') }}" placeholder="e.g. Amazon Marketplace" maxlength="120" style="{{ $inputStyle }};min-width:180px">
            </label>
            <button type="submit" class="cbx-btn cbx-btn--primary cbx-btn--sm">Add sales</button>
        </form>
    </section>
</div>
@endsection
