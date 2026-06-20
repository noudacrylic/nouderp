@extends('layouts.erp')

@section('content')
<div class="flex items-start justify-between gap-3 mb-3 flex-wrap">
    <div>
        <h1 class="text-lg font-semibold">Pembatalan</h1>
        <p class="text-xs text-gray-500">Pesanan marketplace yang <b>pembeli minta batalkan</b> (dari Jubelio) &amp; SO marketplace yang sudah <b>dibatalkan (void)</b>.</p>
    </div>
    {{-- Tombol tarik manual (selain cron) — pola wajib: fitur otomatis + trigger manual. --}}
    <form method="POST" action="{{ route('pos.fulfillment.sync-cancel') }}">
        @csrf
        <button type="submit" class="text-xs px-3 py-2 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">
            🔄 Tarik Permintaan Batal
        </button>
    </form>
</div>

<form method="GET" class="mb-3">
    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari nomor / pelanggan…"
           class="border rounded px-3 py-2 text-sm w-72">
</form>

<div class="space-y-4">
    @forelse($rows as $row)
        @php
            $isVoid    = $row['state'] === 'void';
            $isJblCancel = $row['state'] === 'jubelio_canceled';
            $borderColor = $isVoid ? 'border-l-gray-400' : 'border-l-red-500';
        @endphp
        <div class="bg-white rounded-xl border border-gray-300 border-l-4 {{ $borderColor }} shadow-md p-4">
            {{-- Header --}}
            <div class="flex items-center gap-2 flex-wrap -mx-4 -mt-4 px-4 py-2.5 {{ $isVoid ? 'bg-gray-100 border-gray-200' : 'bg-red-50 border-red-100' }} rounded-t-xl border-b">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-100 text-blue-700">SO</span>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700">🛒 {{ $row['channel'] }}</span>
                @php $copyNumber = str_contains($row['number'], '-') ? \Illuminate\Support\Str::after($row['number'], '-') : $row['number']; @endphp
                <span class="js-copy text-sm font-bold text-gray-800 cursor-pointer hover:text-indigo-600" data-copy="{{ $copyNumber }}" title="Klik untuk salin nomor (tanpa prefix channel)">{{ $row['number'] }}</span>
                @if($isVoid)
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-gray-200 text-gray-700">✗ DIBATALKAN (VOID)</span>
                @elseif($isJblCancel)
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-red-100 text-red-700 ring-1 ring-red-300" title="Dibatalkan di Jubelio — SO masih aktif, perlu void manual">⛔ DIBATALKAN DI JUBELIO</span>
                @else
                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-red-100 text-red-700 ring-1 ring-red-300">⛔ PEMBELI MINTA BATAL</span>
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
                    @if($row['jubelio_no'])
                        <div class="text-[13px] text-gray-500">No. Jubelio: <span class="js-copy cursor-pointer hover:text-indigo-600 font-semibold text-gray-700" data-copy="{{ $row['jubelio_no'] }}" title="Klik untuk salin">{{ $row['jubelio_no'] }}</span></div>
                    @endif
                    <div class="text-[13px] text-gray-500">Total <b class="text-gray-800">Rp {{ number_format($row['grand_total'], 0, ',', '.') }}</b></div>
                </div>

                <div class="space-y-1.5 lg:pl-6 lg:border-l lg:border-gray-100">
                    @unless($isVoid)
                        <div class="text-[13px]">
                            <div class="text-gray-400 font-semibold mb-0.5">{{ $isJblCancel ? '📝 Keterangan' : '📝 Alasan Pembeli' }}</div>
                            <div class="text-gray-700 whitespace-pre-line break-words">{{ $row['cancel_reason'] ?: '— (tidak disebutkan)' }}</div>
                        </div>
                        @if($isJblCancel)
                            <div class="text-[11px] text-amber-600 font-semibold">Sudah ada Faktur/Surat Jalan — void manual: batalkan dokumen turunannya dulu lewat "Lihat SO".</div>
                        @elseif($row['requested_at'])
                            <div class="text-[11px] text-gray-400">Diminta {{ \Carbon\Carbon::parse($row['requested_at'])->format('d M Y H:i') }}</div>
                        @endif
                    @endunless
                    {{-- Status dokumen — penting saat memutuskan batal: faktur/SJ sudah terbit? --}}
                    <div class="text-[12px] flex items-center gap-1.5 flex-wrap">
                        <span class="px-1.5 py-0.5 rounded font-semibold {{ $row['invoice_posted'] ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $row['invoice_posted'] ? '⚠ Faktur sudah terbit' : 'Faktur belum' }}
                        </span>
                        <span class="px-1.5 py-0.5 rounded font-semibold {{ $row['sj_created'] ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $row['sj_created'] ? '⚠ Surat Jalan sudah dibuat' : 'SJ belum' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Footer aksi --}}
            <div class="mt-3 flex items-center gap-2 flex-wrap border-t border-gray-50 pt-3">
                <a href="{{ route('sales.orders.print', $row['id']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🖨 Cetak SO</a>
                <a href="{{ route('sales.orders.show', $row['id']) }}"
                   class="text-xs px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">🔎 Lihat SO</a>
                @unless($isVoid)
                    <form action="{{ route('sales.orders.void', $row['id']) }}" method="POST" class="ml-auto"
                          onsubmit="return confirm('Batalkan (void) SO {{ $row['number'] }}?\n\nReservasi stok dikembalikan. Bila faktur/Surat Jalan sudah terbit, harus di-void lebih dulu dari halaman SO.')">
                        @csrf
                        <button type="submit"
                                class="text-xs px-3 py-1.5 rounded bg-red-600 hover:bg-red-700 text-white font-bold">✗ Batalkan SO (Void)</button>
                    </form>
                @endunless
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">
            Tidak ada permintaan pembatalan maupun pesanan yang dibatalkan.
        </div>
    @endforelse
</div>

@include('erp.pos.fulfillment._copy_js')
@endsection
