@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">Selesai</h1>
        <p class="text-xs text-gray-500">
            Seluruh pesanan yang sudah tuntas — marketplace: ditandai selesai oleh marketplace ·
            ambil di toko: barangnya sudah diambil · kasir: transaksi langsung di toko ·
            kurir: semua paket sudah sampai di pembeli.
        </p>
    </div>
</div>

{{-- Filter sendiri, bukan _filters bersama: di daftar sepanjang ribuan baris yang
     dicari orang adalah RENTANG TANGGAL, bukan nama kurir. Dropdown kurir di tab ini
     juga tak lagi bisa disaring di SQL sejak daftarnya dipaginasi — menyaringnya
     sesudah halaman terambil hanya akan mengosongkan halaman yang isinya ada. --}}
<form method="GET" class="mb-3 flex items-end gap-2 flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Nomor / pelanggan / produk / SKU…"
               class="border rounded px-3 py-1.5 text-sm w-72">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Channel</label>
        <select name="channel" class="border rounded px-2 py-1.5 text-sm bg-white">
            <option value="">Semua</option>
            <option value="marketplace" @selected(request('channel') === 'marketplace')>🛒 Marketplace</option>
            <option value="non" @selected(request('channel') === 'non')>🏬 Non-Marketplace</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Selesai dari</label>
        <input type="date" name="from" value="{{ request('from') }}" class="border rounded px-2 py-1.5 text-sm">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Sampai</label>
        <input type="date" name="to" value="{{ request('to') }}" class="border rounded px-2 py-1.5 text-sm">
    </div>
    @include('erp._partials.per-page-select')
    <button type="submit" class="px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-semibold">Cari</button>
    @if(request('q') || request('channel') || request('from') || request('to'))
        <a href="{{ route('pos.fulfillment.selesai') }}" class="text-xs text-gray-400 hover:text-gray-600 font-semibold pb-2">✕ Reset</a>
    @endif
</form>

<p class="mb-3 text-xs text-gray-500">{{ number_format($rows->total(), 0, ',', '.') }} pesanan selesai</p>

<div class="space-y-5">
    @forelse($rows as $row)
        @if($row['kind'] === 'garansi')
            @php $gd = $row['delivery'] && $row['delivery']->status === 'posted' ? $row['delivery'] : null; @endphp
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-rose-400 shadow-md hover:shadow-lg transition-shadow p-4">
                <div class="flex items-start justify-between gap-3">
                    @include('erp.pos.fulfillment._card_top', ['row' => $row])
                    <span class="text-xs text-gray-500 shrink-0">{{ $row['status_label'] }}</span>
                </div>
                @if($gd)
                    <div class="mt-3 border-t border-gray-50 pt-3 flex items-center justify-between gap-2 flex-wrap text-xs bg-gray-50/70 rounded-lg px-3 py-1.5">
                        <span class="font-semibold text-gray-700">📄 <span class="js-copy cursor-pointer hover:text-indigo-600" data-copy="{{ $gd->delivery_number }}" title="Klik untuk salin nomor SJ">{{ $gd->delivery_number }}</span></span>
                        <a href="{{ route('sales.deliveries.print', $gd->id) }}"
                           class="px-2.5 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">Cetak SJ</a>
                    </div>
                @endif
            </div>
        @elseif($row['kind'] === 'kasir')
            {{-- Transaksi kasir tidak punya Sales Order, jadi tidak ada rantai proses
                 untuk digambar: ia lahir langsung dalam keadaan selesai. Kartunya
                 sengaja ringkas — yang dicari orang di sini cuma nomor, pembeli,
                 nilainya, dan jalan menuju fakturnya. --}}
            <div class="bg-white rounded-xl border border-gray-300 border-l-4 border-l-emerald-400 shadow-md hover:shadow-lg transition-shadow p-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-700 uppercase">Kasir</span>
                            <a href="{{ route('sales.invoices.show', $row['id']) }}"
                               class="font-semibold text-gray-800 hover:text-indigo-600">{{ $row['number'] }}</a>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            {{ $row['customer'] }} ·
                            {{ \Carbon\Carbon::parse($row['date'])->isoFormat('D MMM Y') }}
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="font-bold text-gray-800">Rp {{ number_format($row['total'], 0, ',', '.') }}</div>
                        <a href="{{ route('sales.invoices.print', $row['id']) }}"
                           class="mt-1 inline-block px-2.5 py-1 rounded border border-gray-300 text-xs text-gray-600 hover:bg-gray-50 font-semibold">Cetak Faktur</a>
                    </div>
                </div>
            </div>
        @else
            @include('erp.pos.fulfillment._so_card', ['row' => $row, 'mode' => 'selesai'])
        @endif
    @empty
        <div class="bg-white rounded-xl border border-gray-100 p-8 text-center text-gray-400 text-sm">Belum ada pesanan yang selesai.</div>
    @endforelse
</div>

<div class="mt-4">{{ $rows->links() }}</div>

@include('erp.pos.fulfillment._seller_notes_js')
@include('erp.pos.fulfillment._copy_js')
@include('erp.pos.fulfillment._fokus_js')
@endsection
