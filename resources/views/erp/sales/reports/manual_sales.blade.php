@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Data Awal Penjualan</h1>
            <p class="text-xs text-gray-500 mt-0.5">Input penjualan historis untuk kalkulasi score BOM saat data SO belum lengkap.</p>
        </div>
        <a href="{{ route('sales.reports.product-sales') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Laporan Penjualan</a>
    </div>

    @if(session('error') || $errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm mb-4">
            {{ session('error') }}
            @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
        </div>
    @endif


    <div class="grid grid-cols-12 gap-5">

        {{-- Form Tambah --}}
        <div class="col-span-4">
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5">
                <h3 class="font-bold text-gray-700 mb-4 text-sm">Tambah / Update Data</h3>
                <div class="bg-blue-50 border border-blue-100 rounded-xl px-4 py-3 mb-4 text-xs text-blue-700">
                    Jika sudah ada data untuk produk + bulan yang sama, data akan di-update otomatis.
                </div>
                <form action="{{ route('sales.reports.manual-sales.store') }}" method="POST" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Produk *</label>
                        @include('erp._partials.product_search_select', [
                            'products'    => $products,
                            'name'        => 'product_id',
                            'selected'    => old('product_id'),
                            'placeholder' => '— Cari / pilih produk —',
                            'includeAll'  => false,
                        ])
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Tahun *</label>
                            <input type="number" name="year" value="{{ old('year', $currentYear) }}"
                                   min="2020" max="2099" required
                                   class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Bulan *</label>
                            <select name="month" required
                                    class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
                                @foreach(['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'] as $i => $m)
                                    <option value="{{ $i+1 }}" {{ old('month', $currentMonth) == $i+1 ? 'selected' : '' }}>{{ $m }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Qty Terjual *</label>
                        <input type="number" name="qty" value="{{ old('qty') }}" step="0.01" min="0.01" required
                               placeholder="0"
                               class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Catatan</label>
                        <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Opsional..."
                               class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                    <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                        Simpan Data
                    </button>
                </form>
            </div>

            {{-- Import Excel --}}
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-5 mt-5">
                <h3 class="font-bold text-gray-700 mb-3 text-sm">📥 Import dari Excel</h3>
                <p class="text-xs text-gray-500 mb-3">
                    Unggah banyak baris sekaligus. Cocokkan produk lewat <b>SKU</b>. Data yang sudah ada
                    (SKU + tahun + bulan sama) akan di-update otomatis.
                </p>
                <a href="{{ route('sales.reports.manual-sales.template') }}"
                   class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 mb-3">
                    ⬇ Download template Excel
                </a>
                <form action="{{ route('sales.reports.manual-sales.import') }}" method="POST"
                      enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">File Excel <span class="text-red-500">*</span></label>
                        <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                               class="w-full text-xs border border-gray-200 rounded-xl px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>
                    <button type="submit"
                            class="w-full border border-blue-600 text-blue-600 hover:bg-blue-50 py-2.5 rounded-xl text-sm font-bold transition">
                        Upload & Import
                    </button>
                </form>
            </div>
        </div>

        {{-- List Data --}}
        <div class="col-span-8">
            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Produk</th>
                            <th class="px-4 py-3 text-center font-black text-gray-400 text-[10px] uppercase tracking-widest">Periode</th>
                            <th class="px-4 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Qty</th>
                            <th class="px-4 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Catatan</th>
                            <th class="px-4 py-3 text-center font-black text-gray-400 text-[10px] uppercase tracking-widest">Hapus</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($records as $rec)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="font-bold text-gray-800 text-xs">{{ $rec->product?->sku ?? '—' }}</div>
                                    <div class="text-gray-500 text-xs">{{ $rec->product?->name ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600 text-xs font-bold">{{ $rec->month_label }}</td>
                                <td class="px-4 py-3 text-right font-bold text-gray-700">{{ number_format($rec->qty, 2) }}</td>
                                <td class="px-4 py-3 text-gray-500 text-xs">{{ $rec->notes ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <form action="{{ route('sales.reports.manual-sales.destroy', $rec->id) }}" method="POST"
                                          onsubmit="return confirm('Hapus data ini?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-[10px] text-red-500 hover:text-red-700 font-bold">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center text-gray-400">
                                    Belum ada data awal penjualan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
