@extends('layouts.app')
@section('title', $seller !== null ? 'Edit seller' : 'New seller')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings', ['tab' => 'sellers'])],
        ['label' => $seller !== null ? 'Edit seller' : 'New seller'],
    ]" />
@endsection

@php
    $editing = $seller !== null;
    $action = $editing ? route('billing.settings.sellers.update', $seller->id) : route('billing.settings.sellers.store');
    $curId = old('id', $editing ? $seller->id : '');
    $curLegal = old('legal_name', $editing ? $seller->legal_name : '');
    $curReg = old('registration_number', $editing ? $seller->registration_number : '');
    $curEst = old('establishment', $editing ? $seller->establishment : '');
    $curCur = old('currency', $editing ? $seller->currency : '');
    $curPrefix = old('invoice_prefix', $editing ? $seller->invoice_prefix : '');
    $curDefault = old('is_default', $editing ? $seller->is_default : false);
    // Existing registrations + spare blank rows so a jurisdiction can be added without JS.
    $existing = $editing ? $seller->taxRegistrations->map(fn ($r) => ['country' => $r->country, 'number' => $r->number, 'subdivision' => $r->subdivision, 'scheme' => $r->scheme])->all() : [];
    $rows = old('registrations', array_merge($existing, [[], [], []]));
    $labelStyle = 'display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500';
    $inputStyle = 'height:32px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--foreground);padding:0 8px;font-size:13px';
@endphp

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">{{ $editing ? 'Edit selling entity' : 'New selling entity' }}</h1>
            <p class="cbx-page-desc" style="font-size:13px">The legal entity that issues invoices. Its establishment and registrations are the seller side of the tax outcome.</p>
        </div>
        <a href="{{ route('billing.settings', ['tab' => 'sellers']) }}" class="cbx-btn">Cancel</a>
    </header>

    @include('partials.flash')

    <section class="cbx-panel">
        <form method="POST" action="{{ $action }}" style="padding:16px 20px 20px;display:flex;flex-direction:column;gap:16px">
            @csrf
            @if ($editing)@method('PUT')@endif

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Id
                    <input type="text" name="id" value="{{ $curId }}" {{ $editing ? 'readonly' : 'required' }} maxlength="64" placeholder="cbox-dk" pattern="[a-z0-9._-]+" style="{{ $inputStyle }}{{ $editing ? ';opacity:.6' : '' }}">
                    <span class="mut" style="font-size:11px">Stable handle — lowercase letters, digits, dot, dash, underscore. Fixed once created.</span>
                </label>
                <label style="{{ $labelStyle }}">Legal name
                    <input type="text" name="legal_name" value="{{ $curLegal }}" required maxlength="190" placeholder="Cbox ApS" style="{{ $inputStyle }}">
                </label>
            </div>

            <div class="cbx-grid-3" style="align-items:start">
                <label style="{{ $labelStyle }}">Registration number
                    <input type="text" name="registration_number" value="{{ $curReg }}" required maxlength="64" placeholder="DK12345678" style="{{ $inputStyle }}">
                </label>
                <label style="{{ $labelStyle }}">Establishment
                    <input type="text" name="establishment" value="{{ $curEst }}" required maxlength="2" placeholder="DK" pattern="[A-Za-z]{2}" style="{{ $inputStyle }};text-transform:uppercase">
                    <span class="mut" style="font-size:11px">ISO 3166-1 alpha-2 country.</span>
                </label>
                <label style="{{ $labelStyle }}">Default currency
                    <input type="text" name="currency" value="{{ $curCur }}" required maxlength="3" placeholder="DKK" pattern="[A-Za-z]{3}" style="{{ $inputStyle }};text-transform:uppercase">
                </label>
            </div>

            <div class="cbx-grid-2" style="align-items:start">
                <label style="{{ $labelStyle }}">Invoice-number prefix
                    <input type="text" name="invoice_prefix" value="{{ $curPrefix }}" required maxlength="40" placeholder="CBOX-DK" pattern="[A-Za-z0-9._-]+" style="{{ $inputStyle }}">
                    <span class="mut" style="font-size:11px">Numbers this entity's invoices. Changing it after invoices exist does not renumber them.</span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;align-self:end;padding-bottom:6px">
                    <input type="checkbox" name="is_default" value="1" {{ $curDefault ? 'checked' : '' }}>
                    Default selling entity
                </label>
            </div>

            <div>
                <h2 class="cbx-panel-title" style="font-size:13px;margin:6px 0 4px">Tax registrations</h2>
                <p class="cbx-page-desc" style="font-size:11px;margin:0 0 8px">The entity's VAT/GST nexus per jurisdiction. Leave a row blank to skip it. Rates are never entered here — they resolve from the cited rate-source feeds.</p>
                <table class="tbl">
                    <thead><tr><th style="width:90px">Country</th><th>Number</th><th style="width:130px">Subdivision</th><th style="width:130px">Scheme</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            <tr>
                                <td><input type="text" name="registrations[{{ $i }}][country]" value="{{ $row['country'] ?? '' }}" maxlength="2" placeholder="DK" pattern="[A-Za-z]{2}" style="{{ $inputStyle }};width:70px;text-transform:uppercase"></td>
                                <td><input type="text" name="registrations[{{ $i }}][number]" value="{{ $row['number'] ?? '' }}" maxlength="64" placeholder="DK12345678" style="{{ $inputStyle }};width:100%"></td>
                                <td><input type="text" name="registrations[{{ $i }}][subdivision]" value="{{ $row['subdivision'] ?? '' }}" maxlength="16" placeholder="—" style="{{ $inputStyle }};width:100%"></td>
                                <td><input type="text" name="registrations[{{ $i }}][scheme]" value="{{ $row['scheme'] ?? '' }}" maxlength="32" placeholder="standard" style="{{ $inputStyle }};width:100%"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit" class="cbx-btn cbx-btn--primary">@include('partials.icon', ['name' => 'check', 'size' => 14, 'sw' => 1.7]){{ $editing ? 'Save changes' : 'Create seller' }}</button>
                <a href="{{ route('billing.settings', ['tab' => 'sellers']) }}" class="cbx-btn">Cancel</a>
            </div>
        </form>
    </section>
</div>
@endsection
