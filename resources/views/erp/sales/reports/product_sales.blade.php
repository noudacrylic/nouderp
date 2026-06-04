@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Laporan Penjualan per Produk</h1>
            <p class="text-xs text-gray-500 mt-0.5">Ringkasan qty dan revenue dari Sales Order yang dikonfirmasi.</p>
        </div>
        <a href="{{ route('sales.reports.manual-sales') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            Data Penjualan Manual →
        </a>
    </div>


    {{-- Filter --}}
    <form method="GET" class="bg-white border border-gray-100 rounded-2xl shadow-sm p-4 mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Dari Tanggal</label>
            <input type="date" name="date_from" value="{{ request('date_from', $dateFrom->format('Y-m-d')) }}"
                   class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Sampai Tanggal</label>
            <input type="date" name="date_to" value="{{ request('date_to', $dateTo->format('Y-m-d')) }}"
                   class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Produk</label>
            <select name="product_id" class="border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
                <option value="">— Semua Produk —</option>
                @foreach($products as $p)
                    <option value="{{ $p->id }}" {{ request('product_id') == $p->id ? 'selected' : '' }}>
                        {{ $p->sku }} – {{ $p->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition">
            Tampilkan
        </button>
        <a href="{{ route('sales.reports.product-sales') }}" class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">Reset</a>
    </form>

    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <th class="px-5 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">SKU</th>
                    <th class="px-5 py-3 text-left font-black text-gray-400 text-[10px] uppercase tracking-widest">Produk</th>
                    <th class="px-5 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Qty Terjual</th>
                    <th class="px-5 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">Revenue</th>
                    <th class="px-5 py-3 text-right font-black text-gray-400 text-[10px] uppercase tracking-widest">% Qty</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 font-bold text-blue-600 text-xs font-mono">{{ $row->sku }}</td>
                        <td class="px-5 py-3 text-gray-800">{{ $row->name }}</td>
                        <td class="px-5 py-3 text-right font-bold text-gray-700">{{ number_format($row->total_qty, 2) }}</td>
                        <td class="px-5 py-3 text-right text-gray-600">Rp {{ number_format($row->total_revenue, 0, ',', '.') }}</td>
                        <td class="px-5 py-3 text-right text-gray-500 text-xs">
                            @if($grandTotalQty > 0)
                                {{ number_format(($row->total_qty / $grandTotalQty) * 100, 1) }}%
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-gray-400">
                            Tidak ada data penjualan untuk periode ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if($rows->isNotEmpty())
                <tfoot>
                    <tr class="bg-gray-50 border-t-2 border-gray-200">
                        <td colspan="2" class="px-5 py-3 font-black text-gray-600 text-xs uppercase">Total</td>
                        <td class="px-5 py-3 text-right font-black text-gray-800">{{ number_format($grandTotalQty, 2) }}</td>
                        <td class="px-5 py-3 text-right font-black text-gray-800">Rp {{ number_format($grandTotalRevenue, 0, ',', '.') }}</td>
                        <td class="px-5 py-3 text-right font-bold text-gray-600">100%</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

</div>
@endsection
