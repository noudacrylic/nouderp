@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Retur</h1>
        <p class="text-xs text-gray-500">Pesanan marketplace yang <b>diretur pembeli</b> (dari Jubelio). Cek kondisi barang, lalu <b>post draft retur</b> agar stok &amp; jurnal kembali.</p>
    </div>
    {{-- Tombol tarik manual (selain cron) — pola wajib: fitur otomatis + trigger manual. --}}
    <form method="POST" action="{{ route('pos.fulfillment.sync-retur') }}">
        @csrf
        <button type="submit" class="text-xs px-3 py-2 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
            🔄 Tarik Retur
        </button>
    </form>
</div>

<form method="GET" class="mb-3">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari nomor / pelanggan / produk / SKU…"
           class="border rounded px-3 py-2 text-sm w-72">
</form>

<div class="space-y-4">
    @forelse($rows as $row)
        @php
            $returPosted = ($row['retur_status'] ?? null) === 'posted';
            $returDraft  = ($row['retur_status'] ?? null) === 'draft';
            $borderColor = $returPosted ? 'border-l-emerald-500' : 'border-l-orange-500';
            $jubelioNo   = $row['j_link']->jubelio_salesorder_no ?? null;
        @endphp
        <div class="bg-white rounded-xl border border-gray-300 border-l-4 {{ $borderColor }} shadow-md p-4">
            {{-- Header --}}
            <div class="flex items-center gap-2 flex-wrap -mx-4 -mt-4 px-4 py-2.5 {{ $returPosted ? 'bg-emerald-50 border-emerald-100' : 'bg-orange-50 border-orange-100' }} rounded-t-xl border-b">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-100 text-blue-700">SO</span>
                @if($row['channel'])
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">🛒 {{ $row['channel'] }}</span>
                @endif
                @php $copyNumber = marketplace_copy_number($row['number'], true); @endphp
                <span class="js-copy text-sm font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor (tanpa prefix channel)">{{ $row['number'] }}</span>
                @if($returPosted)
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-700">✓ RETUR DIPROSES</span>
                @else
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-orange-100 text-orange-700 ring-1 ring-orange-300">↩ DIRETUR PEMBELI</span>
                @endif
                <span class="ml-auto text-xs text-gray-500 whitespace-nowrap">
                    {{ $row['date'] ? \Carbon\Carbon::parse($row['date'])->format('d M Y') : '' }}
                </span>
            </div>

            {{-- Body --}}
            <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-x-5 gap-y-2">
                <div class="space-y-1.5">
                    <div class="text-base font-bold text-gray-800">
                        {{ $row['customer'] }}
                        @if($row['phone'])<span class="font-normal text-gray-500 text-xs"> · {{ $row['phone'] }}</span>@endif
                    </div>
                    @if($jubelioNo)
                        <div class="text-[13px] text-gray-500">No. Jubelio: <span class="js-copy cursor-pointer hover:text-indigo-600 font-semibold text-gray-700" data-copy="{{ $jubelioNo }}" title="Klik untuk salin">{{ $jubelioNo }}</span></div>
                    @endif
                    <div class="text-[13px] text-gray-500">Total <b class="text-gray-800">Rp {{ number_format($row['grand_total'], 0, ',', '.') }}</b></div>
                    @if($row['tracking_no'])
                        <div class="text-[13px] text-gray-500">Resi: <span class="js-copy cursor-pointer hover:text-indigo-600 font-semibold text-gray-700" data-copy="{{ $row['tracking_no'] }}" title="Klik untuk salin">{{ $row['tracking_no'] }}</span>@if($row['shipper']) <span class="text-gray-400">· {{ $row['shipper'] }}</span>@endif</div>
                    @endif
                </div>

                <div class="space-y-1.5 lg:pl-6 lg:border-l lg:border-gray-100">
                    {{-- Status draft retur — inti keputusan operator. --}}
                    <div class="text-[13px]">
                        <div class="text-gray-400 font-semibold mb-0.5">↩ Draft Retur</div>
                        @if($row['retur_number'])
                            <div class="text-gray-700 font-semibold">{{ $row['retur_number'] }}
                                @if($returPosted)
                                    <span class="px-1.5 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-700">sudah di-post</span>
                                @else
                                    <span class="px-1.5 py-0.5 rounded text-[11px] font-bold bg-orange-100 text-orange-700">draft — perlu cek barang</span>
                                @endif
                            </div>
                            @if($returDraft)
                                <div class="text-[11px] text-gray-400">Buka retur → set kondisi barang (utuh/perbaikan/rusak) → Post untuk kembalikan stok &amp; jurnal.</div>
                            @endif
                        @else
                            <div class="text-[12px] text-amber-600 font-semibold">Draft retur belum terbentuk. Klik "Tarik Retur" di atas untuk menariknya dari Jubelio, atau buat manual.</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Footer aksi --}}
            <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
                <a href="{{ route('sales.orders.show', $row['id']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🔎 Lihat SO</a>
                @if($row['retur_id'])
                    <a href="{{ route('sales.returns.edit', $row['retur_id']) }}"
                       class="ml-auto text-xs px-3 py-1.5 rounded {{ $returPosted ? 'border border-emerald-300 text-emerald-700 hover:bg-emerald-50' : 'bg-orange-600 hover:bg-orange-700 text-white' }} font-bold">
                        {{ $returPosted ? '📄 Lihat Retur' : '↩ Buka Retur' }}
                    </a>
                @else
                    <a href="{{ route('sales.returns.index') }}"
                       class="ml-auto text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">↩ Modul Retur</a>
                @endif
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">
            Tidak ada pesanan yang sedang diretur.
        </div>
    @endforelse
</div>

@include('erp.pos.fulfillment._copy_js')
@endsection
