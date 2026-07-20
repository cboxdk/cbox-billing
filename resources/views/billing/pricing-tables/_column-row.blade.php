{{--
    One plan-column authoring row of the pricing-table form. Cloned by the "Add column" button
    from a <template> (with `__INDEX__` as the placeholder index). Fields are named
    columns[$i][...]; an empty row (no plan chosen) is skipped server-side.
--}}
@php
    $planId = data_get($col, 'plan_id');
    $annualId = data_get($col, 'annual_plan_id');
    $featured = filter_var(data_get($col, 'featured', false), FILTER_VALIDATE_BOOLEAN);
    $badge = data_get($col, 'badge');
    $highlight = data_get($col, 'highlight');
    $rowInput = $input.';width:100%';
@endphp
<div data-col-row style="border:1px solid var(--border);border-radius:10px;padding:12px;background:var(--secondary)">
    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
        <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Plan
            <select name="columns[{{ $i }}][plan_id]" style="{{ $rowInput }}">
                <option value="">— Select a plan —</option>
                @foreach ($options['plans'] as $plan)
                    <option value="{{ $plan['id'] }}" @selected((string) $planId === (string) $plan['id'])>{{ $plan['name'] }} ({{ $plan['interval'] }})</option>
                @endforeach
            </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Annual plan <span class="mut" style="font-weight:400">(yearly toggle)</span>
            <select name="columns[{{ $i }}][annual_plan_id]" style="{{ $rowInput }}">
                <option value="">— None —</option>
                @foreach ($options['plans'] as $plan)
                    <option value="{{ $plan['id'] }}" @selected((string) $annualId === (string) $plan['id'])>{{ $plan['name'] }} ({{ $plan['interval'] }})</option>
                @endforeach
            </select>
        </label>
        <button type="button" data-col-remove class="cbx-btn cbx-btn--ghost cbx-btn--sm" aria-label="Remove column" title="Remove column">@include('partials.icon', ['name' => 'x', 'size' => 14, 'sw' => 1.8])</button>
    </div>
    <div style="display:grid;grid-template-columns:auto 1fr 1fr;gap:10px;align-items:end;margin-top:10px">
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:500;height:32px">
            <input type="checkbox" name="columns[{{ $i }}][featured]" value="1" @checked($featured)> Featured
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Badge
            <input type="text" name="columns[{{ $i }}][badge]" value="{{ $badge }}" maxlength="40" placeholder="Most popular" style="{{ $rowInput }}">
        </label>
        <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:500">Highlight
            <input type="text" name="columns[{{ $i }}][highlight]" value="{{ $highlight }}" maxlength="120" placeholder="For growing teams" style="{{ $rowInput }}">
        </label>
    </div>
</div>
