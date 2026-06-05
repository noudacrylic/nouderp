@extends('layouts.erp')

@section('content')

<x-transaction-header title="Edit Faktur {{ $invoice->invoice_number }}" />

{{-- HEADER INVOICE: 2 BARIS — struktur sama dengan halaman Create supaya konsisten --}}
<div class="card p-4 mb-4 bg-white shadow-sm border border-gray-100">

    {{-- 🔹 BARIS 1: RELASI & REFERENSI --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        {{-- Sales Order (readonly, edit invoice tidak boleh ganti SO) --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Sales Order</label>
            <input type="text"
                value="{{ $invoice->salesOrder->order_number ?? '-' }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500"
                readonly>
        </div>

        {{-- No PO Customer --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">No. PO Pelanggan</label>
            <input type="text"
                value="{{ $invoice->salesOrder->customer_po_number ?? '-' }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500"
                readonly>
        </div>

        {{-- Uang Muka --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Uang Muka</label>
            <input type="text"
                value="{{ $totalAdvance > 0 ? 'Advance: Rp ' . number_format($totalAdvance, 0, ',', '.') : 'Tidak ada advance' }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 font-medium"
                readonly>
        </div>

        {{-- Surat Jalan --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Surat Jalan</label>
            <input type="text"
                value="{{ $delivery ? $delivery->delivery_number : 'Belum ada delivery' }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 font-medium"
                readonly>
        </div>
    </div>

    {{-- 🔹 BARIS 2: DATA TRANSAKSI UTAMA --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        {{-- Nomor Invoice (sudah ter-generate, bisa diedit jika perlu) --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nomor Faktur</label>
            <input type="text" name="invoice_number" form="transactionForm"
                value="{{ old('invoice_number', $invoice->invoice_number) }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
        </div>

        {{-- Customer (readonly — terikat ke SO) --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1 text-primary">Pelanggan</label>
            <input type="text"
                value="{{ $invoice->customer->name ?? ($invoice->salesOrder->customer->name ?? '-') }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 font-bold"
                readonly>
            <input type="hidden" name="customer_id" id="customer_id" form="transactionForm" value="{{ $invoice->customer_id }}">
        </div>

        {{-- Warehouse --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Gudang</label>
            @php $whSelected = old('warehouse_id', $invoice->warehouse_id); @endphp
            <select name="warehouse_id" form="transactionForm"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
                @foreach($warehouses as $w)
                    <option value="{{ $w->id }}" {{ $whSelected == $w->id ? 'selected' : '' }}>{{ $w->name }}</option>
                @endforeach
            </select>
            <input type="hidden" name="warehouse_id_hidden" id="warehouse_id_hidden" value="{{ $invoice->warehouse_id }}">
        </div>

        {{-- Tanggal --}}
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tanggal</label>
            <input type="date" name="invoice_date" form="transactionForm"
                value="{{ old('invoice_date', \Carbon\Carbon::parse($invoice->invoice_date)->format('Y-m-d')) }}"
                class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
        </div>

    </div>
</div>

<div class="relative">

<x-transaction-form
    type="invoice"
    :customers="$customers"
    :warehouses="$warehouses"
    :products="$products"
    :so="$invoice->salesOrder ?? null"
    :delivery="$delivery ?? null"
    :invoice="$invoice"
    action="{{ route('sales.invoices.update', $invoice->id) }}"
    method="PUT"
/>

<input
    type="hidden"
    name="sales_order_id"
    value="{{ $invoice->sales_order_id }}"
    form="transactionForm"
/>

</div>

@endsection

@section('scripts')
<script>
document.addEventListener("DOMContentLoaded", function () {
    let items = @json($itemsJson ?? []);

    if(typeof loadItemsFromSO === "function"){
        loadItemsFromSO(items);
    }
});
</script>
@endsection






