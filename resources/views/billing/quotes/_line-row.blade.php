@php
    $type = $row['type'] ?? 'plan';
    $dk = $row['discount_kind'] ?? '';
@endphp
<tr>
    <td>
        <select name="lines[{{ $i }}][type]" class="cbx-input">
            <option value="plan" @selected($type === 'plan')>Plan</option>
            <option value="custom" @selected($type === 'custom')>Custom</option>
        </select>
    </td>
    <td>
        <select name="lines[{{ $i }}][plan_id]" class="cbx-input" style="margin-bottom:4px">
            <option value="">— Select a plan —</option>
            @foreach ($plans as $plan)
                <option value="{{ $plan->id }}" @selected((string) ($row['plan_id'] ?? '') === (string) $plan->id)>{{ $plan->name }} ({{ $plan->key }})</option>
            @endforeach
        </select>
        <input name="lines[{{ $i }}][description]" class="cbx-input" placeholder="Custom line description" maxlength="300" value="{{ $row['description'] ?? '' }}">
        <label style="font-size:11px;display:inline-flex;align-items:center;gap:5px;margin-top:4px" class="mut">
            <input type="hidden" name="lines[{{ $i }}][recurring]" value="0">
            <input type="checkbox" name="lines[{{ $i }}][recurring]" value="1" @checked(($row['recurring'] ?? '') == '1')> recurring (part of the subscription)
        </label>
    </td>
    <td><input name="lines[{{ $i }}][quantity]" type="number" min="1" class="cbx-input" value="{{ $row['quantity'] ?? 1 }}"></td>
    <td><input name="lines[{{ $i }}][unit_amount]" type="number" step="0.01" min="0" class="cbx-input" placeholder="0.00" value="{{ $row['unit_amount'] ?? '' }}"></td>
    <td>
        <select name="lines[{{ $i }}][discount_kind]" class="cbx-input" style="margin-bottom:4px">
            <option value="" @selected($dk === '')>No discount</option>
            <option value="percent" @selected($dk === 'percent')>Percent %</option>
            <option value="fixed" @selected($dk === 'fixed')>Fixed amount</option>
        </select>
        <input name="lines[{{ $i }}][discount_value]" type="number" step="0.01" min="0" class="cbx-input" placeholder="value" value="{{ $row['discount_value'] ?? '' }}">
    </td>
    <td><button type="button" class="cbx-btn cbx-btn--ghost cbx-btn--sm" data-remove-row aria-label="Remove line">@include('partials.icon', ['name' => 'x', 'size' => 13, 'sw' => 1.7])</button></td>
</tr>
