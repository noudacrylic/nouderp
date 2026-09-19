@extends('layouts.erp')

@section('content')
@php use App\Http\Controllers\Inventory\InventoryFlowReportController as Lap; @endphp

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Arus Barang &amp; HPP</h1>
        <p class="text-xs text-gray-500 mt-0.5">
            Kapan barang masuk, kapan keluar, dan kapan harga pokoknya benar-benar masuk buku besar.
        </p>
    </div>
</div>

{{-- Ringkasan: semuanya dari baris Surat Jalan periode ini, dipecah menurut ada/tidaknya faktur. --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <div class="bg-white rounded shadow p-4">
        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Barang keluar (nilai FIFO)</div>
        <div class="text-xl font-bold text-gray-800">Rp {{ number_format($ringkasan['keluar'], 0, ',', '.') }}</div>
        <div class="text-[11px] text-gray-400 mt-1">Lewat Surat Jalan pada rentang ini.</div>
    </div>
    <div class="bg-white rounded shadow p-4">
        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Sudah difakturkan</div>
        <div class="text-xl font-bold text-green-600">Rp {{ number_format($ringkasan['difakturkan'], 0, ',', '.') }}</div>
        <div class="text-[11px] text-gray-400 mt-1">Harga pokoknya sudah masuk buku besar (5001).</div>
    </div>
    <div class="bg-white rounded shadow p-4 {{ $ringkasan['belum'] > 0.5 ? 'ring-2 ring-amber-300' : '' }}">
        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Belum difakturkan</div>
        <div class="text-xl font-bold {{ $ringkasan['belum'] > 0.5 ? 'text-amber-600' : 'text-green-600' }}">
            Rp {{ number_format($ringkasan['belum'], 0, ',', '.') }}
        </div>
        <div class="text-[11px] text-gray-400 mt-1">
            Sudah keluar gudang, harga pokoknya belum tercatat.
            <span class="block mt-0.5">Seluruh waktu: <strong>Rp {{ number_format($ringkasan['belum_semua'], 0, ',', '.') }}</strong></span>
        </div>
    </div>
</div>

<p class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded px-3 py-2 mb-3">
    Angka di atas sengaja TIDAK dibandingkan dengan saldo akun 5001 pada bulan yang sama.
    Barang yang keluar akhir Juli baru difakturkan Agustus, jadi perbandingan antar-bulan menghasilkan
    selisih puluhan juta yang cuma beda waktu. Yang perlu diperhatikan adalah kolom
    <strong>Belum difakturkan</strong> yang umurnya sudah lama.
</p>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Produk</label>
        <select name="product_id" class="filter-auto border rounded px-2 py-1.5 max-w-xs">
            <option value="">Semua produk</option>
            @foreach($products as $p)
                <option value="{{ $p->id }}" @selected($productId == $p->id)>{{ $p->sku }} — {{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Gudang</label>
        <select name="warehouse_id" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua gudang</option>
            @foreach($warehouses as $w)
                <option value="{{ $w->id }}" @selected($warehouseId == $w->id)>{{ $w->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari</label>
        <input type="date" name="dari" value="{{ $dari }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="sampai" value="{{ $sampai }}" class="filter-auto border rounded px-2 py-1.5">
    </div>
    @include('erp._partials.per-page-select')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Jenis</th>
                <th class="px-3 py-2 text-left">Dokumen</th>
                <th class="px-3 py-2 text-left">Produk</th>
                <th class="px-3 py-2 text-left">Gudang</th>
                <th class="px-3 py-2 text-right">Masuk</th>
                <th class="px-3 py-2 text-right">Keluar</th>
                <th class="px-3 py-2 text-right">Saldo</th>
                <th class="px-3 py-2 text-right">Nilai FIFO</th>
                <th class="px-3 py-2 text-left">Jurnal HPP</th>
            </tr>
        </thead>
        <tbody>
        @forelse($rows as $r)
            {{-- Status faktur hanya bermakna untuk pengiriman; void-nya adalah pembalikan. --}}
            @php $isSJ = $r->transaction_type === 'sale'; @endphp
            <tr class="border-b hover:bg-blue-50/40">
                <td class="px-3 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->created_at)->format('d M Y H:i') }}</td>
                <td class="px-3 py-2">{{ Lap::labelJenis($r->transaction_type) }}</td>
                <td class="px-3 py-2 text-xs text-gray-600">
                    {{ $r->doc_number ?? ($r->transaction_id ? '#' . $r->transaction_id : '-') }}
                </td>
                <td class="px-3 py-2">
                    {{ $r->product_name ?? '-' }}
                    <div class="text-[11px] text-gray-400">{{ $r->sku }}</div>
                </td>
                <td class="px-3 py-2 text-xs text-gray-600">{{ $r->warehouse_name ?? '-' }}</td>
                <td class="px-3 py-2 text-right {{ (float) $r->qty_in > 0 ? 'text-green-600 font-semibold' : 'text-gray-300' }}">
                    {{ (float) $r->qty_in > 0 ? rtrim(rtrim(number_format($r->qty_in, 2, ',', '.'), '0'), ',') : '-' }}
                </td>
                <td class="px-3 py-2 text-right {{ (float) $r->qty_out > 0 ? 'text-red-600 font-semibold' : 'text-gray-300' }}">
                    {{ (float) $r->qty_out > 0 ? rtrim(rtrim(number_format($r->qty_out, 2, ',', '.'), '0'), ',') : '-' }}
                </td>
                <td class="px-3 py-2 text-right">{{ rtrim(rtrim(number_format($r->balance, 2, ',', '.'), '0'), ',') }}</td>
                <td class="px-3 py-2 text-right">
                    @if(($r->nilai_fifo ?? 0) > 0)
                        {{ number_format($r->nilai_fifo, 0, ',', '.') }}
                    @else
                        <span class="text-gray-300">-</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-xs whitespace-nowrap">
                    @if($r->transaction_type === 'sales_void')
                        <span class="text-gray-400">void</span>
                    @elseif(!$isSJ)
                        <span class="text-gray-300">-</span>
                    @elseif($r->faktur_number ?? null)
                        <span class="text-green-600">✓ {{ $r->faktur_number }}</span>
                    @else
                        <span class="text-amber-600 font-semibold">belum ada faktur</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Tidak ada pergerakan pada rentang ini.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $rows->links() }}</div>

<div class="mt-4 bg-gray-50 border border-gray-200 rounded p-4 text-xs text-gray-600 leading-relaxed">
    <strong class="text-gray-700">Cara membacanya.</strong>
    Baris <em>Penjualan (Surat Jalan)</em> adalah saat barang benar-benar keluar gudang — stok dan lapisan FIFO
    berkurang di situ, tapi buku besar tidak dijurnal sama sekali. Harga pokoknya (<code>Dr 5001 HPP / Cr 1130
    Persediaan</code>) baru ditulis saat <strong>faktur</strong> terbit. Selama kolom <em>Jurnal HPP</em> masih
    berbunyi <span class="text-amber-600 font-semibold">belum ada faktur</span>, barangnya sudah tidak ada di gudang
    tapi buku besar masih menghitungnya sebagai persediaan.
</div>
@endsection
