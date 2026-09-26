@extends('layouts.erp')

@section('content')
@php $diBanding = $tahap === 'banding'; @endphp

<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Retur</h1>
        <p class="text-xs text-gray-500">
            @if($diBanding)
                Retur yang sedang <b>disengketakan ke marketplace</b>. Catat perkembangannya di returnya;
                begitu hasilnya keluar, isi kondisi barang lalu <b>Selesaikan Retur</b>.
            @else
                Retur yang <b>belum diselesaikan</b>. Buka returnya, isi jenis &amp; kondisi barang, lalu
                <b>Selesaikan Retur</b> — stok &amp; jurnal kembali dan pesanannya pindah ke tab Selesai.
                Kalau kasusnya mau disengketakan dulu, pindahkan ke <b>Banding</b>.
            @endif
        </p>
    </div>
    {{-- Tombol tarik manual (selain cron) — pola wajib: fitur otomatis + trigger manual. --}}
    <form method="POST" action="{{ route('pos.fulfillment.sync-retur') }}">
        @csrf
        <button type="submit" class="text-xs px-3 py-2 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
            🔄 Tarik Retur
        </button>
    </form>
</div>

@include('erp.pos.fulfillment._subtabs', ['items' => [
    [
        'label'  => 'Retur Baru',
        'url'    => route('pos.fulfillment.retur', array_filter(['q' => request('q')])),
        'active' => ! $diBanding,
        'count'  => $returCounts['baru'] ?? 0,
    ],
    [
        'label'  => 'Banding',
        'url'    => route('pos.fulfillment.retur', array_filter(['tahap' => 'banding', 'q' => request('q')])),
        'active' => $diBanding,
        'count'  => $returCounts['banding'] ?? 0,
    ],
]])

<form method="GET" class="mb-3 flex items-center gap-2">
    @if($diBanding)<input type="hidden" name="tahap" value="banding">@endif
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari no. retur / retur marketplace / pesanan / pelanggan…"
           class="border rounded px-3 py-2 text-sm w-80">
    <button type="submit" class="text-sm px-3 py-2 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cari</button>
    @if(request('q'))
        <a href="{{ route('pos.fulfillment.retur', array_filter(['tahap' => $diBanding ? 'banding' : null])) }}"
           class="text-xs text-gray-400 hover:text-gray-600 font-semibold">✕ Reset</a>
    @endif
</form>

<div class="space-y-4">
    @forelse($rows as $row)
        @php
            $adaDokumen  = ! empty($row['retur_id']);
            $borderColor = $diBanding ? 'border-l-amber-500' : ($adaDokumen ? 'border-l-orange-500' : 'border-l-gray-400');
            $jubelioNo   = $row['j_link']->jubelio_salesorder_no ?? null;
        @endphp
        <div class="bg-white rounded-xl border border-gray-300 border-l-4 {{ $borderColor }} shadow-md p-4">
            {{-- Header --}}
            <div class="flex items-center gap-2 flex-wrap -mx-4 -mt-4 px-4 py-2.5 {{ $diBanding ? 'bg-amber-50 border-amber-100' : 'bg-orange-50 border-orange-100' }} rounded-t-xl border-b">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-100 text-blue-700">SO</span>
                @if($row['channel'])
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">🛒 {{ $row['channel'] }}</span>
                @endif
                @php $copyNumber = marketplace_copy_number($row['number'], true); @endphp
                <span class="js-copy text-sm font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor (tanpa prefix channel)">{{ $row['number'] }}</span>
                @if($diBanding)
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-amber-100 text-amber-800 ring-1 ring-amber-300">⚖ SEDANG BANDING</span>
                @elseif($adaDokumen)
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-orange-100 text-orange-700 ring-1 ring-orange-300">↩ RETUR BARU</span>
                @else
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-gray-200 text-gray-600 ring-1 ring-gray-300">⚠ BELUM ADA DOKUMEN</span>
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
                    <div class="text-[13px] text-gray-500">Total <b class="text-gray-800">Rp {{ number_format($row['grand_total'] ?? 0, 0, ',', '.') }}</b></div>
                    @if($row['tracking_no'] ?? null)
                        <div class="text-[13px] text-gray-500">Resi: <span class="js-copy cursor-pointer hover:text-indigo-600 font-semibold text-gray-700" data-copy="{{ $row['tracking_no'] }}" title="Klik untuk salin">{{ $row['tracking_no'] }}</span>@if($row['shipper'] ?? null) <span class="text-gray-400">· {{ $row['shipper'] }}</span>@endif</div>
                    @endif
                </div>

                <div class="space-y-1.5 lg:pl-6 lg:border-l lg:border-gray-100">
                    <div class="text-[13px]">
                        <div class="text-gray-400 font-semibold mb-0.5">↩ Dokumen Retur</div>
                        @if($adaDokumen)
                            <div class="text-gray-700 font-semibold">{{ $row['retur_number'] }}</div>
                            @if($row['retur_external'] ?? null)
                                <div class="text-[12px] text-gray-500">No. retur marketplace: <b class="text-gray-700">{{ $row['retur_external'] }}</b></div>
                            @endif
                            <div class="text-[12px] text-gray-500">
                                Jenis:
                                <b class="text-gray-700">{{ \App\Modules\Sales\Models\SalesReturn::RETURN_TYPES[$row['retur_type'] ?? ''] ?? 'belum ditentukan' }}</b>
                                · Nilai <b class="text-gray-700">Rp {{ number_format($row['retur_total'] ?? 0, 0, ',', '.') }}</b>
                            </div>
                            @if($row['retur_notes'] ?? null)
                                <div class="mt-1 text-[12px] text-gray-500 line-clamp-2">📝 {{ $row['retur_notes'] }}</div>
                            @endif
                        @else
                            <div class="text-[12px] text-amber-600 font-semibold">
                                Pesanan ini ditandai diretur oleh marketplace, tapi dokumen returnya belum terbentuk.
                                Klik “Tarik Retur” di atas, atau buat manual dari modul Retur.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Footer aksi --}}
            <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
                @if($row['id'])
                    <a href="{{ route('sales.orders.show', $row['id']) }}"
                       class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🔎 Lihat SO</a>
                @endif

                @if($adaDokumen)
                    {{-- Pemindah tahap. Cuma memindahkan kasus; yang membukukan apa pun
                         hanya "Selesaikan Retur" di dalam form returnya. --}}
                    <form method="POST" action="{{ route('pos.fulfillment.retur-tahap', $row['retur_id']) }}" class="ml-auto">
                        @csrf
                        <input type="hidden" name="tahap" value="{{ $diBanding ? 'baru' : 'banding' }}">
                        <button type="submit"
                                class="text-xs px-3 py-1.5 rounded border font-semibold
                                       {{ $diBanding
                                            ? 'border-gray-300 text-gray-600 hover:bg-gray-50'
                                            : 'border-amber-400 text-amber-700 hover:bg-amber-50' }}">
                            {{ $diBanding ? '← Kembalikan ke Retur Baru' : '⚖ Ajukan Banding' }}
                        </button>
                    </form>
                    <a href="{{ route('sales.returns.edit', $row['retur_id']) }}"
                       class="text-xs px-3 py-1.5 rounded bg-orange-600 hover:bg-orange-700 text-white font-bold">
                        ↩ Buka &amp; Selesaikan Retur
                    </a>
                @else
                    <a href="{{ route('sales.returns.create') }}"
                       class="ml-auto text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">+ Buat Retur</a>
                @endif
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">
            {{ $diBanding ? 'Tidak ada retur yang sedang dibanding.' : 'Tidak ada retur yang menunggu dikerjakan.' }}
        </div>
    @endforelse
</div>

@include('erp.pos.fulfillment._copy_js')
@include('erp.pos.fulfillment._fokus_js')
@endsection
