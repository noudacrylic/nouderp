@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $isWeb = $channel['kind'] === 'internal';
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Harga Produk &mdash; {{ $channel['label'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                HPP diambil dari <a href="{{ route('analisa.hpp.index') }}" class="text-blue-600 font-semibold hover:underline">HPP Produk</a>,
                potongan kanal ditambahkan di sini. Ketik harga &rarr; untung &amp; markup ikut berubah seketika.
                @if($isWeb)
                    <strong>Simpan Harga</strong> memberlakukan harga di web storefront, ERP, sekaligus jadi harga dasar Jubelio.
                @else
                    <strong>Kirim</strong> memberlakukan harga khusus toko ini di Jubelio &mdash; sampai ditekan, produknya dijual di harga dasar.
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('analisa.hpp.index') }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">HPP Produk</a>
        </div>
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
                <option value="markup" @selected($sort === 'markup')>Markup terkecil dulu</option>
                <option value="hpp"    @selected($sort === 'hpp')>HPP terbesar dulu</option>
                <option value="harga"  @selected($sort === 'harga')>Harga terbesar dulu</option>
                <option value="nama"   @selected($sort === 'nama')>Nama produk</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1" title="Isi untuk memunculkan kolom harga usulan">Target markup (%)</label>
            <input type="number" step="1" min="0" name="markup" value="{{ $markup }}" placeholder="mis. 60"
                   class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-32">
        </div>
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Terapkan</button>
        @if(request()->hasAny(['search', 'sort', 'tipe', 'markup']))
            <a href="{{ route('analisa.harga.index', ['kanal' => $channel['key']]) }}" class="text-xs text-slate-500 hover:underline py-2">Reset</a>
        @endif
        <div class="ml-auto">@include('erp._partials.per-page-select')</div>
    </form>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="tabel-harga" style="min-width:1100px"
                   data-percent="{{ $channel['fee']['percent'] }}" data-fixed="{{ $channel['fee']['fixed'] }}">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left font-black">Produk</th>
                        <th class="px-3 py-3 text-right font-black">HPP</th>
                        <th class="px-3 py-3 text-right font-black bg-indigo-50">Harga<br>Jual</th>
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
                        @if($markup !== null)
                            <th class="px-3 py-3 text-right font-black bg-emerald-50">Usulan<br>{{ $angka($markup, 0) }}%</th>
                        @endif
                        <th class="px-5 py-3 text-right font-black">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($rows as $row)
                        @php $pid = $row['product']['id']; $s = $row['satuan']; @endphp
                        <tr class="hover:bg-blue-50/40 align-top" data-hpp="{{ $row['hpp'] }}">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">
                                    {{ $row['product']['sku'] }}
                                    @if($row['product']['sale_type'] === 'bundle')
                                        <span class="ml-1 text-[10px] font-sans font-bold text-slate-400 uppercase">bundle</span>
                                    @endif
                                </div>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                @if($row['hpp'] === null)
                                    <span class="text-amber-600 text-xs font-semibold" title="Produk ini belum punya sampel produksi maupun harga perolehan, jadi untungnya belum bisa dihitung.">belum ada</span>
                                @else
                                    <span class="font-bold text-slate-800">{{ $rp($row['hpp']) }}</span>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-right bg-indigo-50/60">
                                <form method="POST" action="{{ route('analisa.harga.save', $pid) }}" class="flex items-center justify-end gap-1">
                                    @csrf
                                    <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
                                    <input type="text" name="price" value="{{ $row['price'] ? number_format($row['price'], 0, ',', '.') : '' }}"
                                           placeholder="0" class="rupiah-input js-harga border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                                    <button class="text-indigo-700 hover:text-indigo-900 text-xs font-bold" title="Simpan harga">✓</button>
                                </form>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600">
                                <span class="js-potongan">{{ $rp($s['deduction']) }}</span>
                                <span class="block text-[10px] text-slate-400 js-potongan-persen">
                                    {{ $s['deduction_percent'] === null ? '' : $angka($s['deduction_percent']) . '% efektif' }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                <span class="js-untung font-black {{ ($s['profit'] ?? 0) < 0 ? 'text-red-600' : 'text-slate-800' }}">
                                    {{ $rp($s['profit']) }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                @include('erp.analisa.harga._persen', ['sc' => $s])
                            </td>

                            @if($markup !== null)
                                <td class="px-3 py-3 text-right bg-emerald-50/60 tabular-nums">
                                    @if($row['suggested'])
                                        <button type="button" class="text-emerald-700 hover:text-emerald-900 font-bold js-pakai"
                                                data-harga="{{ (int) $row['suggested'] }}" title="Isikan ke kolom harga">
                                            {{ $rp($row['suggested']) }}
                                        </button>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                            @endif

                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                @if($isWeb)
                                    <span class="text-[10px] text-slate-400">web + ERP + harga dasar</span>
                                @else
                                    @if($row['price_source'] === 'dasar')
                                        <span class="text-[10px] font-semibold text-slate-400" title="Belum punya harga khusus kanal ini — di tokonya dijual dengan harga dasar (harga web).">ikut harga dasar</span>
                                    @elseif($row['is_pushed'])
                                        <span class="text-[10px] font-semibold text-emerald-600">terkirim {{ $row['pushed_at']?->format('d/m/y') }}</span>
                                    @else
                                        <span class="text-[10px] font-semibold text-amber-600">belum dikirim</span>
                                    @endif
                                    <form method="POST" action="{{ route('analisa.harga.push', $pid) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
                                        <button class="ml-2 border border-slate-200 text-slate-600 hover:bg-slate-50 px-2.5 py-1 rounded-lg text-[11px] font-bold">Kirim</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $markup !== null ? 8 : 7 }}" class="px-5 py-12 text-center text-slate-400">
                                Belum ada produk yang ditandai dijual. Tandai <strong>Dijual</strong> di master produk supaya muncul di sini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500 max-w-3xl">
            <strong>Potongan dipungut dari harga jual, bukan dari HPP.</strong> Menaikkan harga ikut menaikkan
            potongannya, jadi untung tidak naik sebesar kenaikan harganya. Biaya tetap
            ({{ 'Rp' . number_format($channel['fee']['fixed'], 0, ',', '.') }}) ditanggung sekali per pesanan &mdash;
            itu sebabnya barang murah terasa jauh lebih berat potongannya, dan borongan terasa ringan.
        </div>
        {{ $rows->links() }}
    </div>
</div>

@include('erp.analisa.harga._hitung')
@endsection
