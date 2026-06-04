@php
    $invoice = $invoice ?? null;
    $so = $invoice->salesOrder ?? ($so ?? null);
    $global_discount_type = $global_discount_type ?? ($invoice->global_discount_type ?? 'nominal');
    $global_discount_value = $global_discount_value ?? ($invoice->global_discount_value ?? 0);
    $shipping_cost = $shipping_cost ?? ($invoice->shipping_cost ?? 0);
    $ppn_percent = $ppn_percent ?? ($invoice->ppn_percent ?? 0);
    $pph_percent = $pph_percent ?? ($invoice->pph_percent ?? 0);
    $customer_id = $invoice->customer_id ?? ($so->customer_id ?? '');
    $invoice_date = $invoice->invoice_date ?? date('Y-m-d');
@endphp

<div class="row mb-4">
    <div class="col-md-6">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select" id="customer_id">
            <option value="">-- Select Customer --</option>
            @foreach($customers as $c)
                <option value="{{ $c->id }}" {{ $customer_id == $c->id ? 'selected' : '' }}>
                    {{ $c->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Tanggal Invoice</label>
        <input type="date" name="invoice_date" value="{{ $invoice_date }}" class="form-control">
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <label class="form-label">Warehouse</label>
        @php $whSelected = old('warehouse_id', $invoice->warehouse_id ?? ($so->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId())); @endphp
        <select name="warehouse_id" class="form-select">
            @foreach($warehouses as $w)
                <option value="{{ $w->id }}" {{ $whSelected == $w->id ? 'selected' : '' }}>
                    {{ $w->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        @if($so)
            <label class="form-label">Sales Order Reference</label>
            <input type="text" value="{{ $so->order_number }}" class="form-control" readonly>
            <input type="hidden" name="sales_order_id" value="{{ $so->id }}">
        @endif
    </div>
</div>

<hr>

<h4>Items</h4>
<div class="table-responsive">
    <table class="table table-bordered">
        <thead class="bg-light">
            <tr>
                <th>SKU</th>
                <th>Product</th>
                <th style="width: 100px;">Stock</th>
                <th style="width: 100px;">Unit</th>
                <th style="width: 120px;">Qty</th>
                <th style="width: 180px;">Unit Price</th>
                <th style="width: 200px;">Discount</th>
            </tr>
        </thead>
        <tbody>
            @if(isset($invoice) && $invoice->items)
                @foreach($invoice->items as $i => $item)
                    <tr class="item-row">
                        <td>
                            <input type="text" name="items[{{$i}}][sku]" value="{{ $item->product->sku ?? '' }}" class="form-control" readonly>
                            <input type="hidden" name="items[{{$i}}][id]" value="{{ $item->id }}">
                            <input type="hidden" name="items[{{$i}}][product_id]" value="{{ $item->product_id }}">
                            <input type="hidden" name="items[{{$i}}][sales_order_item_id]" value="{{ $item->sales_order_item_id }}">
                        </td>
                        <td>
                            <input type="text" value="{{ $item->product->name ?? '' }}" class="form-control" readonly>
                        </td>
                        <td>{{ $item->product->stock ?? '-' }}</td>
                        <td>{{ $item->unit ?? 'pcs' }}</td>
                        <td>
                            <input type="number" step="0.0001" name="items[{{$i}}][qty]" value="{{ old("items.$i.qty", $item->qty) }}" class="form-control">
                        </td>
                        <td>
                            <input type="number" step="0.01" name="items[{{$i}}][unit_price]" value="{{ old("items.$i.unit_price", $item->unit_price) }}" class="form-control">
                        </td>
                        <td>
                            <div class="input-group">
                                <select name="items[{{$i}}][discount_type]" class="form-select" style="max-width: 70px;">
                                    <option value="percent" {{ (old("items.$i.discount_type", $item->discount_type ?? 'nominal') == 'percent') ? 'selected' : '' }}>%</option>
                                    <option value="nominal" {{ (old("items.$i.discount_type", $item->discount_type ?? 'nominal') == 'nominal') ? 'selected' : '' }}>Rp</option>
                                </select>
                                <input type="number" step="0.01" name="items[{{$i}}][discount_value]" value="{{ old("items.$i.discount_value", $item->discount_value ?? 0) }}" class="form-control">
                            </div>
                        </td>
                    </tr>
                @endforeach
            @else
                {{-- Mode Create manual atau Fallback --}}
                <tr class="item-row">
                    <td colspan="2">
                        <select name="items[0][product_id]" class="form-select select2">
                            <option value="">-- Select Product --</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}">{{ $p->sku }} - {{ $p->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>-</td>
                    <td>-</td>
                    <td><input type="number" name="items[0][qty]" class="form-control" value="0"></td>
                    <td><input type="number" name="items[0][unit_price]" class="form-control" value="0"></td>
                    <td>
                        <div class="input-group">
                            <select name="items[0][discount_type]" class="form-select">
                                <option value="percent">%</option>
                                <option value="nominal" selected>Rp</option>
                            </select>
                            <input type="number" name="items[0][discount_value]" class="form-control" value="0">
                        </div>
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

<hr>

<div class="row mt-4">
    <div class="col-md-6">
        <label class="form-label">Global Discount</label>
        <div class="input-group">
            <select name="global_discount_type" class="form-select" style="max-width: 120px;">
                <option value="percent" {{ $global_discount_type == 'percent' ? 'selected' : '' }}>Percent (%)</option>
                <option value="nominal" {{ $global_discount_type == 'nominal' ? 'selected' : '' }}>Nominal (Rp)</option>
            </select>
            <input type="number" step="0.01" name="global_discount_value" value="{{ $global_discount_value }}" class="form-control">
        </div>
    </div>
    <div class="col-md-3">
        <label class="form-label">PPN %</label>
        <input type="number" step="0.01" name="ppn_percent" value="{{ $ppn_percent }}" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label">PPh %</label>
        <input type="number" step="0.01" name="pph_percent" value="{{ $pph_percent }}" class="form-control">
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <label class="form-label">Shipping Cost</label>
        <input type="number" step="0.01" name="shipping_cost" value="{{ $shipping_cost }}" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label">Selling Expense</label>
        <input type="number" step="0.01" name="selling_expense" value="{{ $invoice->selling_expense ?? 0 }}" class="form-control">
    </div>
</div>