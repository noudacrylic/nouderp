@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Import Invoice Masal &mdash; Shopee Order Export</h1>
    <a href="{{ route('sales.invoices.index') }}" class="text-sm text-gray-600 hover:underline">&larr; Kembali ke daftar</a>
</div>

@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="bg-red-50 border border-red-200 rounded p-3 mb-3 text-sm">
        <div class="font-semibold text-red-800 mb-1">Baris yang gagal:</div>
        <ul class="list-disc pl-5 text-red-700 space-y-0.5">
            @foreach(session('import_errors') as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if(session('import_successes') && count(session('import_successes')) > 0)
    <div class="bg-green-50 border border-green-200 rounded p-3 mb-3 text-sm">
        <div class="font-semibold text-green-800 mb-1">Invoice berhasil dibuat &amp; dipost:</div>
        <ul class="list-disc pl-5 text-green-700 space-y-0.5 max-h-48 overflow-auto">
            @foreach(session('import_successes') as $ok)
                <li>{{ $ok }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="bg-white rounded shadow p-4 max-w-2xl">
    <form method="POST" action="{{ route('sales.invoices.excel-import.store') }}" enctype="multipart/form-data">
        @csrf
        <label class="block text-sm font-medium mb-2">File Excel (.xlsx / .xls / .csv)</label>
        <input type="file" name="file" required accept=".xlsx,.xls,.csv"
               class="block w-full text-sm border rounded px-3 py-2 mb-3">

        <div class="flex gap-2">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                Import &amp; Post
            </button>
        </div>
    </form>

    <div class="mt-6 text-sm text-gray-700">
        <div class="font-semibold mb-1">Cara pakai:</div>
        <ul class="list-disc pl-5 space-y-1">
            <li>Upload file <code>Order.*.xlsx</code> langsung dari <b>Shopee Seller Center</b> (Pesanan &raquo; Selesai &raquo; Ekspor) &mdash; <b>tanpa diedit</b>.</li>
            <li>Per <b>No. Pesanan</b>: bikin 1 Sales Order (status confirmed) + 1 Sales Invoice (langsung POSTED). Multi-item per pesanan dirangkum (max 5 item).</li>
            <li><b>Match produk</b> via kolom <code>Nomor Referensi SKU</code> &rarr; <code>products.sku</code> di Noud ERP.</li>
            <li><b>Customer</b> dipaksa ke master <code>"Shopee"</code> (id harus ada di master). Warehouse = warehouse pertama.</li>
            <li><code>customer_po_number</code> di SO = <b>No. Pesanan Shopee</b> &mdash; ini kunci match nanti saat upload <b>Marketplace Settlement</b>.</li>
            <li>Status yang diterima: <b>"Selesai"</b> &amp; <b>"Pesanan diterima, namun pembeli masih dapat mengajukan pengembalian..."</b>. Status lain (batal/return) di-skip.</li>
            <li>No. Pesanan yang sudah pernah diimport untuk Shopee di-skip (tidak di-import ulang).</li>
            <li>Stok produk harus cukup &amp; tanggal harus dalam periode terbuka (period guard).</li>
            <li>Pesanan yang gagal di-skip; pesanan lain tetap diproses. Detail muncul setelah import.</li>
        </ul>
    </div>
</div>
@endsection
