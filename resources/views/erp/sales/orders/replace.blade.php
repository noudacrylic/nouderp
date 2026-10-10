@extends('erp.layouts.transaction')

@section('content')

    <x-transaction-header title="Ganti Pesanan {{ $old->order_number }}" />

    @php $fmt = fn($n) => 'Rp' . number_format($n, 0, ',', '.'); @endphp
    <div class="bg-amber-50 border border-amber-300 text-amber-900 rounded-lg px-4 py-3 mb-4 text-sm">
        <div class="font-semibold mb-1">
            Mengganti {{ $old->order_number }} — total {{ $fmt($old->grand_total) }}, DP sudah dibayar {{ $fmt($dpPaid) }}
        </div>
        <ul class="list-disc ml-5 space-y-0.5 text-[13px]">
            <li>Ubah item/ukuran di bawah, lalu simpan (tombol simpan mana pun). SO baru langsung di-post.</li>
            <li>DP dipindah ke SO baru <b>tanpa</b> void pembayaran — tanggal &amp; catatan kasnya tetap.</li>
            <li>Total baru lebih besar → sisanya jadi tagihan (link bayar baru). Lebih kecil → kelebihan DP masuk <b>Saldo Pelanggan</b>.</li>
            <li>{{ $old->order_number }} otomatis di-void, link bayarnya mati. Pelanggan harus tetap {{ $old->customer?->name }}.</li>
        </ul>
    </div>

    <input type="hidden" name="replaces_sales_order_id" value="{{ $old->id }}" form="transactionForm">

    <x-transaction-form
        type="sales_order"
        :customers="$customers"
        :warehouses="$warehouses"
        :products="$products"
        :so="$so"
        action="{{ route('sales.orders.store') }}"
        method="POST"
    />

    @include('erp._partials.print-shortcut-form')

@endsection
