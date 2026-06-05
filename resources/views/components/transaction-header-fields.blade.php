@props([
    'customers' => collect(),
    'warehouses' => collect(),
    'quotations' => collect(),
    'quotation' => null,
    'so' => null,
    'invoice' => null,
    'type' => 'quotation',
])

<div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
    {{-- BARIS 1: SOURCE, CUSTOMER, PO --}}
    
    {{-- PILIH PENAWARAN (Hanya untuk SO) --}}
    @if($type === 'sales_order')
    <div>
        <label>Nomor Penawaran</label>
        <select id="quotation_select" class="form-control w-full border rounded px-3 py-2 text-sm bg-white">
            <option value="">-- Manual (Tanpa Penawaran) --</option>
            @if(isset($quotations))
                @foreach($quotations as $q)
                    <option value="{{ $q->id }}" {{ (isset($quotation) && $quotation->id == $q->id) ? 'selected' : '' }}>
                        {{ $q->quotation_number }} - {{ $q->customer->name ?? '-' }}
                    </option>
                @endforeach
            @endif
        </select>
    </div>
    @else
    <div>
        {{-- Spacer to maintain 3-col grid for non-SO types --}}
    </div>
    @endif

    {{-- CUSTOMER --}}
    <div>
        <label>Pelanggan</label>
        <div class="customer-select">
            @php
                $customerId = old('customer_id', $quotation->customer_id ?? ($so->customer_id ?? ($invoice->customer_id ?? '')));
                $customerName = '';
                if ($customerId) {
                    $selectedCustomer = $customers->firstWhere('id', $customerId);
                    $customerName = $selectedCustomer ? $selectedCustomer->name : '';
                }
            @endphp
            <input type="hidden" name="customer_id" id="customer_id" value="{{ $customerId }}">
            
            <div class="flex">
                <input type="text" 
                       id="customer_search" 
                       name="customer_name"
                       class="form-control w-full border border-gray-300 rounded-l px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500 border-r-0" 
                       placeholder="Cari pelanggan..."
                       value="{{ old('customer_name', $customerName) }}">

                <button type="button" onclick="openQuickCustomer(this)"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-3 rounded-r font-bold transition-colors">
                    +
                </button>
            </div>

            <div class="customer-dropdown"></div>
        </div>
    </div>

    {{-- NO PO CUSTOMER --}}
    <div>
        <label>No. PO Pelanggan</label>
        <input type="text" 
               name="customer_po_number" 
               class="form-control w-full border rounded px-3 py-2 text-sm"
               placeholder="Opsional (Misal: PO-123)"
               value="{{ old('customer_po_number', $so->customer_po_number ?? ($invoice->customer_po_number ?? '')) }}">
    </div>

    {{-- BARIS 2: LOGISTICS & TIME --}}

    {{-- NOMOR --}}
    <div>
        <label>Nomor {{ $type == 'quotation' ? 'Penawaran' : ($type == 'invoice' ? 'Faktur' : 'Sales Order') }}</label>
        <div class="flex items-center gap-2">
            @php
                $numberField = $type == 'quotation' ? 'quotation_number' : ($type == 'invoice' ? 'invoice_number' : 'order_number');
                $numberVal = old($numberField, $quotation->$numberField ?? ($so->$numberField ?? ($invoice->$numberField ?? '')));
            @endphp
            <input type="checkbox" id="autoNumber" {{ !$numberVal ? 'checked' : '' }} class="h-4 w-4">
            <input type="text" name="{{ $numberField }}" id="quotationNumber" 
                placeholder="[ Otomatis ]" 
                {{ !$numberVal ? 'disabled' : '' }}
                value="{{ $numberVal }}"
                class="form-control w-full border rounded px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-400">
        </div>
    </div>

    {{-- WAREHOUSE --}}
    <div>
        <label>Gudang</label>
        <select name="warehouse_id" class="form-control w-full border rounded px-3 py-2 text-sm bg-white">
            @php
                $selectedWH = old('warehouse_id', $quotation->warehouse_id ?? ($so->warehouse_id ?? ($invoice->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId())));
            @endphp
            @foreach($warehouses as $wh)
                <option value="{{ $wh->id }}" {{ $selectedWH == $wh->id ? 'selected' : '' }}>
                    {{ $wh->name }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- TANGGAL --}}
    <div>
        <label>Tanggal</label>
        @php
            $dateField = $type == 'quotation' ? 'quotation_date' : ($type == 'invoice' ? 'invoice_date' : 'order_date');
            $dateValue = old($dateField, $quotation->$dateField ?? ($so->$dateField ?? ($invoice->$dateField ?? date('Y-m-d'))));
        @endphp
        <input type="date" name="{{ $dateField }}" value="{{ $dateValue }}"
            class="form-control w-full border rounded px-3 py-2 text-sm">
    </div>
</div>