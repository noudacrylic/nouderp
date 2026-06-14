@extends('erp.layouts.transaction')

@section('content')

<x-transaction-header title="Edit Quotation" />

{{-- HEADER QUOTATION: 1 BARIS (RINGKAS) --}}
<div class="card p-4 mb-4 bg-white shadow-sm border border-gray-100">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <!-- Nomor Penawaran -->
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nomor Penawaran</label>
            <div class="flex items-center gap-2">
                <input type="checkbox" id="autoNumber" class="h-4 w-4">
                <input type="text" name="quotation_number" id="quotationNumber"
                    value="{{ old('quotation_number', $quotation->quotation_number) }}"
                    form="transactionForm"
                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>

        <!-- Customer -->
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pelanggan</label>
            <div class="customer-select relative">
                <input type="hidden" name="customer_id" id="customer_id" form="transactionForm"
                    value="{{ old('customer_id', $quotation->customer_id) }}">
                <div class="flex">
                    <input type="text"
                           id="customer_search"
                           value="{{ $quotation->customer->name ?? '' }}"
                           class="form-control w-full border border-gray-200 rounded-l-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition"
                           placeholder="Cari pelanggan..."
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
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Gudang</label>
            @php $whSelected = old('warehouse_id', $quotation->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId()); @endphp
            <select name="warehouse_id" form="transactionForm" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none transition appearance-none">
                @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ $whSelected == $wh->id ? 'selected' : '' }}>{{ $wh->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- Tanggal -->
        @php
            $qDate = $quotation->quotation_date
                ? \Carbon\Carbon::parse($quotation->quotation_date)->format('Y-m-d')
                : date('Y-m-d');
        @endphp
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tanggal</label>
            <input type="date" name="quotation_date"
                value="{{ old('quotation_date', $qDate) }}"
                form="transactionForm"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
        </div>

        {{-- Metode Pengiriman kini ada di kartu "Pengiriman & Ongkir" (shipping-embed) di bawah. --}}

    </div>
</div>

<x-transaction-form
    type="quotation"
    :customers="$customers"
    :warehouses="$warehouses"
    :products="$products"
    :quotation="$quotation"
    action="{{ route('sales.quotations.update', $quotation->id) }}"
    method="PUT"
/>

@include('erp._partials.print-shortcut-form')

@endsection
