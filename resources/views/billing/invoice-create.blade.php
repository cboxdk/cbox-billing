@extends('layouts.app')
@section('title', 'New invoice')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Invoices', 'href' => route('billing.invoices')],
        ['label' => 'New invoice'],
    ]" />
@endsection

@php
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">New invoice</h1>
            <p class="cbx-page-desc" style="font-size:13px">An ad-hoc, one-off invoice. Lines are priced &amp; taxed through the engine for the customer's place of supply — you enter net amounts in minor units.</p>
        </div>
        <a href="{{ route('billing.invoices') }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ route('billing.invoices.store') }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf

            <label style="{{ $labelStyle }};max-width:420px">Customer
                <select name="organization_id" required style="{{ $inputStyle }}">
                    <option value="">Select an organization…</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org->id }}" @selected(old('organization_id', $selectedOrg) === $org->id)>{{ $org->name }}@if($org->billing_currency) · {{ $org->billing_currency }}@endif</option>
                    @endforeach
                </select>
            </label>

            <div>
                <div class="cbx-label" style="font-size:12px;font-weight:600;margin-bottom:8px">Lines</div>
                <table class="tbl" id="lines-table">
                    <thead><tr><th>Description</th><th style="width:100px">Qty</th><th style="width:200px">Unit net (minor units)</th><th style="width:40px"></th></tr></thead>
                    <tbody id="lines-body">
                        @php($old = old('lines', [['description' => '', 'quantity' => 1, 'amount_minor' => '']]))
                        @foreach ($old as $i => $row)
                            <tr>
                                <td><input name="lines[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" placeholder="e.g. Onboarding fee" maxlength="190" style="{{ $inputStyle }};width:100%"></td>
                                <td><input name="lines[{{ $i }}][quantity]" type="number" min="1" value="{{ $row['quantity'] ?? 1 }}" style="{{ $inputStyle }};width:100%"></td>
                                <td><input name="lines[{{ $i }}][amount_minor]" type="number" min="0" value="{{ $row['amount_minor'] ?? '' }}" placeholder="50000" class="num" style="{{ $inputStyle }};width:100%"></td>
                                <td><button type="button" class="cbx-btn cbx-btn--ghost cbx-btn--sm" data-remove-line>×</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button type="button" class="cbx-btn cbx-btn--secondary cbx-btn--sm" style="margin-top:10px" id="add-line">@include('partials.icon', ['name' => 'plus', 'size' => 14, 'sw' => 1.7]) Add line</button>
            </div>

            <div style="display:flex;gap:8px">
                <button type="submit" class="cbx-btn cbx-btn--primary">Issue invoice</button>
                <a href="{{ route('billing.invoices') }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>

<script>
    (function(){
        var body = document.getElementById('lines-body'), btn = document.getElementById('add-line');
        var style = "{{ $inputStyle }}";
        btn.addEventListener('click', function(){
            var i = body.querySelectorAll('tr').length;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input name="lines['+i+'][description]" placeholder="Description" maxlength="190" style="'+style+';width:100%"></td>' +
                '<td><input name="lines['+i+'][quantity]" type="number" min="1" value="1" style="'+style+';width:100%"></td>' +
                '<td><input name="lines['+i+'][amount_minor]" type="number" min="0" placeholder="50000" class="num" style="'+style+';width:100%"></td>' +
                '<td><button type="button" class="cbx-btn cbx-btn--ghost cbx-btn--sm" data-remove-line>×</button></td>';
            body.appendChild(tr);
        });
        // One delegated handler removes a line row — both the server-rendered rows and the
        // ones added above, so there is no inline onclick and one mechanism for both.
        body.addEventListener('click', function(e){
            var rm = e.target.closest('[data-remove-line]');
            if (rm) { e.preventDefault(); rm.closest('tr').remove(); }
        });
    })();
</script>
@endsection
