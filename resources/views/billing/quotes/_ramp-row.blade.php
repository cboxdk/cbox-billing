<tr>
    <td><input name="ramp[{{ $i }}][from_period_index]" type="number" min="0" class="cbx-input" value="{{ $row['from_period_index'] ?? 0 }}"></td>
    <td><input name="ramp[{{ $i }}][amount]" type="number" step="0.01" min="0" class="cbx-input" placeholder="0.00" value="{{ $row['amount'] ?? '' }}"></td>
    <td><button type="button" class="cbx-btn cbx-btn--ghost cbx-btn--sm" data-remove-row aria-label="Remove step">@include('partials.icon', ['name' => 'x', 'size' => 13, 'sw' => 1.7])</button></td>
</tr>
