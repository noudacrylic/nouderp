@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Afiliasi &mdash; {{ $channel['label'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                Komisi afiliasi dipungut dari harga jual, persis seperti potongan marketplace &mdash; jadi ikut
                program afiliasi sama saja dengan menaikkan persentase potongan.
                Kolom <strong>Harga usulan</strong> menjawab pertanyaan lanjutannya: harganya harus jadi berapa
                supaya untungnya kembali seperti sebelum ikut afiliasi.
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
                <option value="markup" @selected($sort === 'markup')>Markup terkecil dulu</option>
                <option value="hpp"    @selected($sort === 'hpp')>HPP terbesar dulu</option>
                <option value="harga"  @selected($sort === 'harga')>Harga terbesar dulu</option>
                <option value="nama"   @selected($sort === 'nama')>Nama produk</option>
            </select>
        </div>
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Terapkan</button>
        @if(request()->hasAny(['search', 'sort', 'tipe']))
            <a href="{{ route('analisa.harga.afiliasi', ['kanal' => $channel['key']]) }}" class="text-xs text-slate-500 hover:underline py-2">Reset</a>
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
                        <th class="px-3 py-3 text-right font-black">Harga<br>{{ $channel['label'] }}</th>
                        <th class="px-3 py-3 text-right font-black bg-amber-50">Komisi<br>Afiliasi</th>
                        <th class="px-3 py-3 text-right font-black">Potongan<br>+ afiliasi</th>
                        <th class="px-3 py-3 text-right font-black">Untung</th>
                        <th class="px-3 py-3 text-right font-black">
                            Laba
                            <span class="block normal-case tracking-normal text-[10px] mt-0.5">
                                <span class="text-emerald-600">margin</span>
                                <span class="text-slate-300">/</span>
                                <span class="text-indigo-600">markup</span>
                            </span>
                        </th>
                        <th class="px-5 py-3 text-right font-black bg-emerald-50">Harga usulan<br>agar untung tetap</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($rows as $row)
                        @php $pid = $row['product']['id']; $a = $row['affiliate']; $s = $row['satuan']; @endphp
                        <tr class="hover:bg-blue-50/40 align-top" data-hpp="{{ $row['hpp'] }}">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums font-bold text-slate-800">{{ $rp($row['hpp']) }}</td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">
                                {{ $rp($row['price']) }}
                                {{-- Harga dibaca dari tab Harga; di sini cuma dipakai berhitung. --}}
                                <input type="hidden" class="js-harga" value="{{ (int) ($row['price'] ?? 0) }}">
                                <span class="block text-[10px] text-slate-400">
                                    tanpa afiliasi: markup {{ $s['markup_percent'] === null ? '—' : $angka($s['markup_percent']) . '%' }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right bg-amber-50/60">
                                <form method="POST" action="{{ route('analisa.harga.afiliasi.save', $pid) }}" class="flex items-center justify-end gap-1">
                                    @csrf
                                    <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
                                    <input type="number" step="0.5" min="0" max="100" name="affiliate_percent"
                                           value="{{ rtrim(rtrim(number_format($row['affiliate_percent'], 2, '.', ''), '0'), '.') }}"
                                           class="js-afiliasi border border-slate-200 rounded-lg px-2 py-1 text-sm w-20 text-right">
                                    <span class="text-xs text-slate-400">%</span>
                                    <button class="text-amber-700 hover:text-amber-900 text-xs font-bold" title="Simpan persentase">✓</button>
                                </form>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600">
                                <span class="js-potongan">{{ $rp($a['deduction']) }}</span>
                                <span class="block text-[10px] text-slate-400 js-potongan-persen">
                                    {{ $a['deduction_percent'] === null ? '' : $angka($a['deduction_percent']) . '% efektif' }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                <span class="js-untung font-black {{ ($a['profit'] ?? 0) < 0 ? 'text-red-600' : 'text-slate-800' }}">
                                    {{ $rp($a['profit']) }}
                                </span>
                                @if($s['profit'] !== null && $a['profit'] !== null)
                                    <span class="block text-[10px] text-slate-400">
                                        berkurang {{ $rp($s['profit'] - $a['profit']) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                @include('erp.analisa.harga._persen', ['sc' => $a])
                            </td>

                            <td class="px-5 py-3 text-right bg-emerald-50/60 tabular-nums">
                                @if($row['affiliate_suggested'])
                                    <div class="font-black text-emerald-700">{{ $rp($row['affiliate_suggested']) }}</div>
                                    @if($row['price'])
                                        <div class="text-[10px] text-slate-400">
                                            naik {{ $angka(($row['affiliate_suggested'] / $row['price'] - 1) * 100) }}%
                                        </div>
                                    @endif
                                    <form method="POST" action="{{ route('analisa.harga.save', $pid) }}" class="mt-1"
                                          onsubmit="return confirm('Pakai harga {{ $rp($row['affiliate_suggested']) }} sebagai harga {{ $channel['label'] }}?')">
                                        @csrf
                                        <input type="hidden" name="kanal" value="{{ $channel['key'] }}">
                                        <input type="hidden" name="price" value="{{ (int) $row['affiliate_suggested'] }}">
                                        <button class="text-[11px] font-bold text-emerald-700 hover:underline">pakai harga ini</button>
                                    </form>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-12 text-center text-slate-400">Belum ada produk yang ditandai dijual.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500 max-w-3xl">
            Persentase afiliasi disimpan per produk &mdash; produk yang tidak diikutkan program cukup diisi 0.
            Angka bawaannya {{ number_format(\App\Modules\Analysis\Services\ChannelPricingService::DEFAULT_AFFILIATE_PERCENT, 0) }}%.
            Komisi afiliasi <strong>tidak bisa dikirim</strong> lewat Jubelio; yang diatur di sini hanya hitungannya,
            programnya sendiri tetap diatur di seller center.
        </div>
        {{ $rows->links() }}
    </div>
</div>

@include('erp.analisa.harga._hitung')
@endsection
