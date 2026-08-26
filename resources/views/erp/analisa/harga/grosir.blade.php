@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Harga Grosir &mdash; {{ $channel['label'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                &ldquo;Kalau beli 5 saya kasih harga segini, untung saya berapa.&rdquo; Biaya tetap
                ({{ $rp($channel['fee']['fixed']) }}) ditanggung <strong>sekali per pesanan</strong>, jadi makin
                banyak isinya makin ringan &mdash; persentasenya sendiri tidak berubah.
            </p>
        </div>
        <a href="{{ route('analisa.harga.index', ['kanal' => $channel['key']]) }}"
           class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Tab Harga</a>
    </div>

    @include('erp.analisa._mode-asumsi')

    @include('erp.analisa._hitung-ulang')

    @include('erp.analisa.harga._kanal')

    <form method="GET" class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4 mb-4 flex flex-wrap items-end gap-3">
        {{-- Mode asumsi ikut terbawa saat filter diterapkan, supaya tidak diam-diam mati. --}}
        @if(request()->boolean('asumsi'))<input type="hidden" name="asumsi" value="1">@endif
        <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Cari Produk</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama / SKU…"
                   class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-56">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Jenis</label>
            <select name="tipe" class="border border-slate-200 rounded-xl px-3 py-2 text-sm">
                <option value="">Semua</option>
                <option value="satuan" @selected(request('tipe') === 'satuan')>Satuan</option>
                <option value="bundle" @selected(request('tipe') === 'bundle')>Bundle</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Urutkan</label>
            <select name="sort" class="border border-slate-200 rounded-xl px-3 py-2 text-sm">
                <option value="markup" @selected($sort === 'markup')>Markup satuan terkecil dulu</option>
                <option value="hpp"    @selected($sort === 'hpp')>HPP terbesar dulu</option>
                <option value="nama"   @selected($sort === 'nama')>Nama produk</option>
            </select>
        </div>
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Terapkan</button>
        @if(request()->hasAny(['search', 'sort', 'tipe']))
            <a href="{{ route('analisa.harga.grosir', ['kanal' => $channel['key']]) }}" class="text-xs text-slate-500 hover:underline py-2">Reset</a>
        @endif
        <div class="ml-auto">@include('erp._partials.per-page-select')</div>
    </form>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="tabel-harga" style="min-width:1240px"
                   data-percent="{{ $channel['fee']['percent'] }}" data-fixed="{{ $channel['fee']['fixed'] }}">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left font-black">Produk</th>
                        <th class="px-3 py-3 text-right font-black">HPP</th>
                        <th class="px-3 py-3 text-right font-black">Harga<br>Satuan</th>
                        <th class="px-3 py-3 text-center font-black bg-indigo-50">Min<br>Beli</th>
                        <th class="px-3 py-3 text-right font-black bg-indigo-50">Harga<br>Grosir</th>
                        <th class="px-3 py-3 text-right font-black">Total<br>Pesanan</th>
                        <th class="px-3 py-3 text-right font-black">Potongan</th>
                        <th class="px-3 py-3 text-right font-black">Untung</th>
                        <th class="px-3 py-3 text-right font-black">
                            Laba
                            <span class="block normal-case tracking-normal text-[10px] mt-0.5">
                                <span class="text-emerald-600">margin</span>
                                <span class="text-slate-300">/</span>
                                <span class="text-indigo-600">markup</span>
                            </span>
                        </th>
                        <th class="px-5 py-3 text-right font-black bg-emerald-50">Usulan<br>setara satuan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($rows as $row)
                        @php $pid = $row['product']['id']; $g = $row['grosir']; $s = $row['satuan']; @endphp
                        <tr class="hover:bg-blue-50/40 align-top" data-hpp="{{ $row['hpp'] }}">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums font-bold text-slate-800">{{ $rp($row['hpp']) }}</td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600">
                                {{ $rp($row['price']) }}
                                <span class="block text-[10px] text-slate-400">
                                    markup {{ $s['markup_percent'] === null ? '—' : $angka($s['markup_percent']) . '%' }}
                                </span>
                            </td>

                            {{-- Satu form untuk dua kolom: minimum beli dan harganya memang satu keputusan. --}}
                            <td class="px-3 py-3 text-center bg-indigo-50/60">
                                <form method="POST" action="{{ route('analisa.harga.grosir.save', $pid) }}" id="grosir-{{ $pid }}">
                                    @csrf
                                    <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
                                    <input type="number" min="1" step="1" name="wholesale_min_qty" value="{{ $g['min_qty'] }}" placeholder="—"
                                           class="js-qty border border-slate-200 rounded-lg px-2 py-1 text-sm w-16 text-center">
                                </form>
                            </td>

                            <td class="px-3 py-3 text-right bg-indigo-50/60">
                                <div class="flex items-center justify-end gap-1">
                                    <input type="text" name="wholesale_price" form="grosir-{{ $pid }}"
                                           value="{{ $g['price'] ? number_format($g['price'], 0, ',', '.') : '' }}" placeholder="0"
                                           class="rupiah-input js-grosir border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                                    <button form="grosir-{{ $pid }}" class="text-indigo-700 hover:text-indigo-900 text-xs font-bold" title="Simpan harga grosir">✓</button>
                                </div>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600 js-total">{{ $rp($g['revenue']) }}</td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600">
                                <span class="js-potongan">{{ $rp($g['deduction']) }}</span>
                                <span class="block text-[10px] text-slate-400 js-potongan-persen">
                                    {{ $g['deduction_percent'] === null ? '' : $angka($g['deduction_percent']) . '% efektif' }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                <span class="js-untung font-black {{ ($g['profit'] ?? 0) < 0 ? 'text-red-600' : 'text-slate-800' }}">
                                    {{ $rp($g['profit']) }}
                                </span>
                                <span class="block text-[10px] text-slate-400">se-pesanan</span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                @include('erp.analisa.harga._persen', ['sc' => $g])
                            </td>

                            <td class="px-5 py-3 text-right bg-emerald-50/60 tabular-nums">
                                @if($row['grosir_suggested'])
                                    <button type="button" class="font-black text-emerald-700 hover:text-emerald-900 js-pakai"
                                            data-harga="{{ (int) $row['grosir_suggested'] }}"
                                            title="Harga grosir yang untungnya (persen) sama dengan penjualan satuan">
                                        {{ $rp($row['grosir_suggested']) }}
                                    </button>
                                @else
                                    <span class="text-slate-300" title="Isi minimum beli dulu">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-5 py-12 text-center text-slate-400">Belum ada produk yang ditandai dijual.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500 max-w-3xl">
            <strong>Harga grosir tidak dikirim ke marketplace.</strong> Jubelio hanya menerima satu harga per toko,
            jadi tingkatan grosir tetap diatur sendiri di seller center &mdash; halaman ini alat hitungnya, supaya
            angka yang diketik di sana bukan tebakan.
        </div>
        {{ $rows->links() }}
    </div>
</div>

@include('erp.analisa.harga._hitung')
@endsection
