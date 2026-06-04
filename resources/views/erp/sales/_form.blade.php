<hr>

<h4>Global Discount</h4>

<select name="global_discount_type">
    <option value="percent" {{ old('global_discount_type', $quotation->global_discount_type ?? '') == 'percent' ? 'selected' : '' }}>
        Percent (%)
    </option>

    <option value="nominal" {{ old('global_discount_type', $quotation->global_discount_type ?? '') == 'nominal' ? 'selected' : '' }}>
        Nominal (Rp)
    </option>
</select>

<input type="number" name="global_discount_value" step="0.01"
    value="{{ old('global_discount_value', $quotation->global_discount_value ?? 0) }}">

<hr>

<h3>Tax</h3>

<div>
    <label>PPN %</label>
    <input type="number" step="0.01" name="ppn_percent" value="{{ old('ppn_percent', $quotation->ppn_percent ?? 0) }}">
</div>

<div>
    <label>PPh %</label>
    <input type="number" step="0.01" name="pph_percent" value="{{ old('pph_percent', $quotation->pph_percent ?? 0) }}">
</div>

<hr>

<h3>Other</h3>

<div>
    <label>Shipping Cost</label>
    <input type="number" step="0.01" name="shipping_cost"
        value="{{ old('shipping_cost', $quotation->shipping_charge ?? 0) }}">
</div>

<div>
    <label>Selling Expense</label>
    <input type="number" step="0.01" name="selling_expense"
        value="{{ old('selling_expense', $quotation->service_charge ?? 0) }}">
</div>

<hr>