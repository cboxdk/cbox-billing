@extends('layouts.app')
@section('title', 'Email templates')
@section('breadcrumb')
    <x-breadcrumb :items="[
        ['label' => 'Settings', 'href' => route('billing.settings')],
        ['label' => 'Emails'],
    ]" />
@endsection

@section('screen')
<div class="page">
    <header class="cbx-page-header">
        <div>
            <h1 class="cbx-page-title" style="font-size:20px">Transactional emails</h1>
            <p class="cbx-page-desc" style="font-size:13px">Every lifecycle email — branded per selling entity, localized, and editable. Each event resolves through a chain (seller override → account override → shipped default) so it always renders something correct. Pick a scope to see what each event renders from, then edit it with a live preview.</p>
        </div>
    </header>

    @include('partials.flash')

    {{-- Scope selector: view the resolved source account-wide or for one selling entity. --}}
    <section class="cbx-panel" style="margin-bottom:14px">
        <form method="GET" action="{{ route('billing.settings.emails') }}" style="display:flex;align-items:center;gap:12px;padding:12px 20px;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500">
                Branding &amp; overrides for
                <select name="seller" onchange="this.form.submit()" style="height:32px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--foreground);padding:0 8px;font-size:13px">
                    @foreach ($sellers as $id => $label)
                        <option value="{{ $id }}" @selected(($sellerScope->id ?? '') === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <span class="mut" style="font-size:12px">A seller scope shows that entity's overrides layered over the account-wide ones and the shipped defaults.</span>
        </form>
    </section>

    <section class="cbx-panel">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Event</th>
                    @foreach ($locales as $code => $name)
                        <th style="width:170px">{{ $name }} <span class="mut num" style="font-weight:400">{{ $code }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td style="vertical-align:top">
                            <div style="font-weight:600;font-size:13px">{{ $row['event']->label() }}</div>
                            <div class="mut" style="font-size:11px;max-width:340px">{{ $row['event']->description() }}</div>
                        </td>
                        @foreach ($locales as $code => $name)
                            @php $cell = $row['cells'][$code]; @endphp
                            <td style="vertical-align:top">
                                @if ($cell['has_override'])
                                    <span class="cbx-pill cbx-pill--success" style="font-size:10px"><span class="dot"></span>Overridden</span>
                                @else
                                    <span class="cbx-pill cbx-pill--muted" style="font-size:10px">Default</span>
                                @endif
                                <div class="mut" style="font-size:10px;margin:3px 0 6px">{{ $cell['source']->label() }}</div>
                                <a href="{{ $cell['edit_url'] }}" class="cbx-btn cbx-btn--sm">Edit</a>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <p class="mut" style="font-size:12px;margin:12px 4px 0">Per-seller branding (logo, accent colour, from-name, footer) lives on each <a href="{{ route('billing.settings', ['tab' => 'sellers']) }}" style="color:var(--primary)">selling entity</a>. Emails wrap every template in that entity's brand.</p>
</div>
@endsection
