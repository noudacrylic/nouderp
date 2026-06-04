@extends('erp.layouts.transaction')

@section('content')

<x-transaction-header title="Create Quotation" />

{{-- HEADER QUOTATION: 1 BARIS (RINGKAS) --}}
<div class="card p-4 mb-4 bg-white shadow-sm border border-gray-100">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <!-- Nomor Penawaran -->
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nomor Penawaran</label>
            <div class="flex items-center gap-2">
                <input type="checkbox" id="autoNumber" checked class="h-4 w-4">
                <input type="text" name="quotation_number" id="quotationNumber" 
                    placeholder="[ Otomatis ]" 
                    disabled
                    form="transactionForm"
                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-400">
            </div>
        </div>

        <!-- Customer -->
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Customer</label>
            <div class="customer-select relative">
                <input type="hidden" name="customer_id" id="customer_id" form="transactionForm">
                <div class="flex">
                    <input type="text" 
                           id="customer_search" 
                           class="form-control w-full border border-gray-200 rounded-l-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition" 
                           placeholder="Cari customer..."
                           form="transactionForm">
                    <button type="button" onclick="openQuickCustomer(this)"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-3 rounded-r-lg font-bold transition-colors">
                        +
                    </button>
                </div>
                <div class="customer-dropdown"></div>
            </div>
        </div>

        <!-- Warehouse -->
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Warehouse</label>
            @php $whSelected = old('warehouse_id', (isset($quotation) ? $quotation->warehouse_id : null) ?? \App\Core\Inventory\Warehouse::defaultId()); @endphp
            <select name="warehouse_id" form="transactionForm" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none transition appearance-none">
                @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ $whSelected == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- Tanggal -->
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tanggal</label>
            <input type="date" name="quotation_date" value="{{ date('Y-m-d') }}" form="transactionForm"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
        </div>

        <!-- Metode Pengiriman -->
        @include('erp._partials.delivery-method-field', ['model' => $quotation ?? null, 'formAttr' => 'transactionForm'])

    </div>
</div>

<x-transaction-form
    type="quotation"
    :customers="$customers"
    :warehouses="$warehouses"
    :products="$products"
    action="{{ route('sales.quotations.store') }}"
    method="POST"
/>

@include('erp._partials.print-shortcut-form')

@endsection