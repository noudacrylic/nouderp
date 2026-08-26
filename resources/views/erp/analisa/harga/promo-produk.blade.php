@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Promo per Produk &mdash; {{ $channel['label'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                Andaian pembeli mengambil <strong>1 pcs</strong>. Isi diskonnya &mdash; berlaku ke semua baris,
                dan tiap baris masih bisa diubah sendiri &mdash; lalu lihat untung yang tersisa.
                Kolom <strong>diskon maks</strong> adalah batas sebelum barisnya rugi.
            </p>
        </div>
        <a href="{{ route('analisa.harga.promo', ['kanal' => $channel['key']]) }}"
           class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Simulasi Transaksi</a>
    </div>

    @include('erp.analisa._mode-asumsi')

    @include('erp.analisa._hitung-ulang')

    @include('erp.analisa.harga._promo-nav', ['mode' => 'produk'])

    @include('erp.analisa.harga._kanal')

    <form method="GET" class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4 mb-4 flex flex-wrap items-end gap-3">
        {{-- Mode asumsi ikut terbawa saat filter diterapkan, supaya tidak diam-diam mati. --}}
        @if(request()->boolean('asumsi'))<input type="hidden" name="asumsi" value="1">@endif
        <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Diskon (%)</label>
            <input type="number" step="0.5" min="0" max="100" name="diskon" value="{{ $diskon ?: '' }}" placeholder="mis. 15"
                   class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-28 text-right">
        </div>
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
                <option value="markup" @selected($sort === 'markup')>Markup terkecil dulu</option>
                <option value="hpp"    @selected($sort === 'hpp')>HPP terbesar dulu</option>
                <option value="harga"  @selected($sort === 'harga')>Harga terbesar dulu</option>
                <option value="nama"   @selected($sort === 'nama')>Nama produk</option>
            </select>
        </div>
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Terapkan</button>
        @if(request()->hasAny(['search', 'sort', 'tipe', 'diskon']))
            <a href="{{ route('analisa.harga.promo.produk', ['kanal' => $channel['key']]) }}" class="text-xs text-slate-500 hover:underline py-2">Reset</a>
        @endif
        <div class="ml-auto">@include('erp._partials.per-page-select')</div>
    </form>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="tabel-promo" style="min-width:1120px"
                   data-percent="{{ $channel['fee']['percent'] }}" data-fixed="{{ $channel['fee']['fixed'] }}">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left font-black">Produk</th>
                        <th class="px-3 py-3 text-right font-black">HPP</th>
                        <th class="px-3 py-3 text-right font-black">Harga</th>
                        <th class="px-3 py-3 text-right font-black bg-rose-50">Diskon %</th>
                        <th class="px-3 py-3 text-right font-black">Harga setelah<br>diskon</th>
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
                        <th class="px-5 py-3 text-right font-black bg-emerald-50">Diskon<br>maks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($rows as $row)
                        @php $s = $row['setelah']; @endphp
                        <tr class="hover:bg-blue-50/40 align-top"
                            data-hpp="{{ $row['hpp'] }}" data-harga="{{ (int) ($row['price'] ?? 0) }}">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums font-bold text-slate-800">{{ $rp($row['hpp']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-600">{{ $rp($row['price']) }}</td>

                            <td class="px-3 py-3 text-right bg-rose-50/60">
                                <input type="number" min="0" max="100" step="0.5" value="{{ $diskon ?: 0 }}"
                                       class="js-diskon border border-slate-200 rounded-lg px-2 py-1 text-sm w-20 text-right">
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums font-semibold text-slate-800 js-harga-net">
                                {{ $rp($row['harga_diskon']) }}
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600 js-potongan">{{ $rp($s['deduction']) }}</td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                <span class="js-untung font-black {{ ($s['profit'] ?? 0) < 0 ? 'text-red-600' : 'text-slate-800' }}">
                                    {{ $rp($s['profit']) }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                @include('erp.analisa.harga._persen', ['sc' => $s])
                            </td>

                            <td class="px-5 py-3 text-right bg-emerald-50/60 tabular-nums">
                                @if($row['diskon_maks'] === null)
                                    <span class="text-red-500 text-xs font-bold" title="Tanpa diskon pun barisnya sudah tidak untung">
                                        tidak ada
                                    </span>
                                @else
                                    <span class="font-black {{ $row['diskon_maks'] < $diskon ? 'text-red-600' : 'text-emerald-700' }}">
                                        {{ $angka($row['diskon_maks']) }}%
                                    </span>
                                    <span class="block text-[10px] text-slate-400">
                                        sampai {{ $rp($row['price'] * (1 - $row['diskon_maks'] / 100)) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-12 text-center text-slate-400">Belum ada produk yang ditandai dijual.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500 max-w-3xl">
            Potongan kanal ikut terhitung ({{ rtrim(rtrim(number_format($channel['fee']['percent'], 2, ',', '.'), '0'), ',') }}%
            + {{ $rp($channel['fee']['fixed']) }}), jadi diskon yang aman di Website belum tentu aman di marketplace &mdash;
            ganti kanalnya di atas untuk membandingkan.
        </div>
        {{ $rows->links() }}
    </div>
</div>

<script>
(function () {
    const tabel = document.getElementById('tabel-promo');
    if (!tabel) return;

    const PERSEN = parseFloat(tabel.dataset.percent || '0');
    const TETAP  = parseFloat(tabel.dataset.fixed || '0');
    const WARNA  = ['text-red-600', 'text-amber-600', 'text-emerald-600', 'text-indigo-600', 'text-slate-300'];

    const rupiah = (v) => v === null ? '—' : 'Rp' + Math.round(v).toLocaleString('id-ID');
    const persen = (v) => v === null ? '—' : v.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';

    function tulisPersen(baris, kelas, nilai, jenis) {
        const el = baris.querySelector(kelas);
        if (!el) return;
        el.textContent = persen(nilai);
        const wrap = el.parentElement;
        WARNA.forEach((c) => wrap.classList.remove(c));
        if (nilai === null)                        wrap.classList.add('text-slate-300');
        else if (nilai < 0)                        wrap.classList.add('text-red-600');
        else if (jenis === 'margin' && nilai < 20) wrap.classList.add('text-amber-600');
        else                                       wrap.classList.add(jenis === 'margin' ? 'text-emerald-600' : 'text-indigo-600');
    }

    function hitung(baris) {
        const hpp   = baris.dataset.hpp === '' ? null : parseFloat(baris.dataset.hpp);
        const harga = parseFloat(baris.dataset.harga || '0');
        const disc  = parseFloat(String(baris.querySelector('.js-diskon').value).replace(',', '.')) || 0;

        const net = harga * (1 - disc / 100);
        baris.querySelector('.js-harga-net').textContent = harga ? rupiah(net) : '—';

        if (!harga) return;

        const potongan = net * (PERSEN / 100) + TETAP;
        const untung   = hpp === null ? null : net - potongan - hpp;

        baris.querySelector('.js-potongan').textContent = rupiah(potongan);

        const elUntung = baris.querySelector('.js-untung');
        elUntung.textContent = untung === null ? '—' : rupiah(untung);
        elUntung.classList.toggle('text-red-600', untung !== null && untung < 0);
        elUntung.classList.toggle('text-slate-800', untung === null || untung >= 0);

        tulisPersen(baris, '.js-margin', untung === null || net <= 0 ? null : untung / net * 100, 'margin');
        tulisPersen(baris, '.js-markup', untung === null || hpp <= 0 ? null : untung / hpp * 100, 'markup');
    }

    tabel.addEventListener('input', (e) => {
        if (e.target.matches('.js-diskon')) hitung(e.target.closest('tr'));
    });
})();
</script>
@endsection
