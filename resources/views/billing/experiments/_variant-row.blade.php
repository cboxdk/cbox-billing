{{-- One authoring row for an experiment variant: control radio, label, weight, served table.
     `$index` is the array key (a server row index, or __INDEX__ in the clone template). --}}
<div data-variant-row style="display:grid;grid-template-columns:80px 1fr 90px 1fr 32px;gap:8px;align-items:center;border:1px solid var(--border);border-radius:8px;padding:8px 10px">
    <label style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600" title="The control (baseline) variant">
        <input type="radio" name="control" value="{{ $index }}" @checked($variant['is_control'])>
        Control
    </label>
    <input type="text" name="variants[{{ $index }}][label]" value="{{ $variant['label'] }}" maxlength="80" placeholder="Variant label" style="{{ $input }}" aria-label="Variant label">
    <input type="number" name="variants[{{ $index }}][weight]" value="{{ $variant['weight'] }}" min="0" max="1000000" style="{{ $input }}" aria-label="Traffic weight" title="Relative traffic weight">
    <select name="variants[{{ $index }}][served_pricing_table_id]" style="{{ $input }}" aria-label="Served pricing table">
        <option value="">Base table (unchanged)</option>
        @foreach ($tables as $table)
            <option value="{{ $table['id'] }}" @selected((string) ($variant['served_pricing_table_id'] ?? '') === (string) $table['id'])>{{ $table['name'] }}</option>
        @endforeach
    </select>
    <button type="button" data-remove-variant class="cbx-btn cbx-btn--ghost cbx-btn--sm" title="Remove variant" style="width:32px;padding:0">@include('partials.icon', ['name' => 'x', 'size' => 14, 'sw' => 1.7])</button>
</div>
