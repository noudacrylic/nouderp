@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4 gap-3">
    <div>
        <h1 class="text-lg font-semibold">Isi Gudang — {{ $warehouse->name }}</h1>
        <div class="text-xs text-gray-500 mt-0.5">
            {{ $warehouse->location ?: '—' }}
            @if($warehouse->is_sellable)
                <span class="ml-2 px-1.5 py-0.5 rounded text-[10px] uppercase bg-green-100 text-green-700">Bisa Dijual</span>
            @endif
        </div>
    </div>
    <div class="flex gap-2 items-center">
        <a href="{{ route('inventory.warehouses.ledger', $warehouse->id) }}" class="text-xs text-gray-500 underline hover:text-gray-700">Lihat ledger</a>
        <a href="{{ route('inventory.warehouses.edit', $warehouse->id) }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Edit</a>
        <a href="{{ route('inventory.warehouses.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
    </div>
</div>

<div class="grid grid-cols-2 gap-3 mb-4">
    <div class="bg-white rounded shadow p-3">
        <div class="text-[11px] text-gray-500 uppercase tracking-wide">Total Nilai Stok</div>
        <div class="text-lg font-semibold text-gray-800">Rp {{ number_format($totalStockValue, 0, ',', '.') }}</div>
        <div class="text-[11px] text-gray-400 mt-0.5">{{ $stocks->count() }} produk · {{ number_format($stocks->sum('qty_on_hand'), 2, ',', '.') }} qty</div>
    </div>
    <div class="bg-white rounded shadow p-3">
        <div class="text-[11px] text-gray-500 uppercase tracking-wide">Total Nilai Aset Tetap</div>
        <div class="text-lg font-semibold text-gray-800">Rp {{ number_format($totalAssetValue, 0, ',', '.') }}</div>
        <div class="text-[11px] text-gray-400 mt-0.5">{{ $assets->count() }} aset (book value)</div>
    </div>
</div>

<div class="bg-white rounded shadow mb-4">
    <div class="px-3 py-2 border-b flex items-center justify-between">
        <h2 class="font-semibold text-sm">Stok Produk</h2>
        <a href="{{ route('inventory.warehouses.stock') }}" class="text-xs text-gray-500 underline">Lihat semua gudang</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">SKU</th>
                    <th class="px-3 py-2 text-left">Produk</th>
                    <th class="px-3 py-2 text-right">Qty</th>
                    <th class="px-3 py-2 text-right">Harga Satuan</th>
                    <th class="px-3 py-2 text-right">Nilai</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stocks as $s)
                    <tr class="border-b hover:bg-blue-50">
                        <td class="px-3 py-2 font-medium">{{ $s->product->sku ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $s->product->name ?? '-' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($s->qty_on_hand, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right text-gray-600">{{ number_format($s->unit_cost, 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right font-medium">{{ number_format($s->stock_value, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Tidak ada stok produk di gudang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white rounded shadow">
    <div class="px-3 py-2 border-b flex items-center justify-between">
        <h2 class="font-semibold text-sm">Aset Tetap</h2>
        <a href="{{ route('fixed-assets.index', ['warehouse_id' => $warehouse->id]) }}" class="text-xs text-gray-500 underline">Buka di modul Aset Tetap</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">Kode</th>
                    <th class="px-3 py-2 text-left">Nama</th>
                    <th class="px-3 py-2 text-left">Kategori</th>
                    <th class="px-3 py-2 text-left">Tgl Perolehan</th>
                    <th class="px-3 py-2 text-right">Biaya</th>
                    <th class="px-3 py-2 text-right">Nilai Buku</th>
                    <th class="px-3 py-2 text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assets as $asset)
                    @php
                        [$statusLabel, $statusCls] = match($asset->status) {
                            'draft'    => ['Draf', 'bg-yellow-100 text-yellow-700'],
                            'active'   => ['Aktif', 'bg-green-100 text-green-700'],
                            'disposed' => ['Disposed', 'bg-gray-200 text-gray-600'],
                            default    => [ucfirst($asset->status), 'bg-gray-100 text-gray-500'],
                        };
                    @endphp
                    <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('fixed-assets.show', $asset->id) }}">
                        <td class="px-3 py-2 font-medium">{{ $asset->asset_code }}</td>
                        <td class="px-3 py-2">{{ $asset->name }}</td>
                        <td class="px-3 py-2">{{ $asset->category->name ?? '-' }}</td>
                        <td class="px-3 py-2">{{ optional($asset->acquisition_date)->format('d M Y') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($asset->acquisition_cost, 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right font-medium">{{ number_format($asset->current_book_value, 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Tidak ada aset tetap di gudang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
