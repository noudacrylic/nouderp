@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp ' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $jam   = fn (?float $detik) => $detik === null ? '—' : number_format($detik / 3600, 2, ',', '.');
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">HPP Bundle</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Dirakit dari HPP komponen di tab <a href="{{ route('analisa.hpp.index') }}" class="text-blue-600 font-semibold hover:underline">Ready</a>
                &times; jumlah per paket, ditambah packing sekali.
                <strong>Angka analisa</strong> untuk menetapkan harga &mdash; tidak pernah dijurnal.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('analisa.hpp.index') }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">HPP Ready</a>
            <a href="{{ route('analisa.biaya-divisi.index') }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Fixed Cost</a>
        </div>
    </div>

    @include('erp.analisa._mode-asumsi')

    @include('erp.analisa._hitung-ulang')

    @include('erp.analisa.hpp._basis')

    {{-- Filter --}}
    <form method="GET" class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4 mb-4 flex flex-wrap items-end gap-3">
        {{-- Mode asumsi ikut terbawa saat filter diterapkan, supaya tidak diam-diam mati. --}}
        @if(request()->boolean('asumsi'))<input type="hidden" name="asumsi" value="1">@endif
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Cari Bundle</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama / SKU…"
                   class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-56">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Urutkan</label>
            <select name="sort" class="border border-slate-200 rounded-xl px-3 py-2 text-sm">
                <option value="margin" @selected($sort === 'margin')>Margin terkecil dulu</option>
                <option value="hpp"    @selected($sort === 'hpp')>HPP terbesar dulu</option>
                <option value="nama"   @selected($sort === 'nama')>Nama produk</option>
            </select>
        </div>
        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-semibold">Terapkan</button>
        @if(request()->hasAny(['search', 'sort']))
            <a href="{{ route('analisa.hpp.bundle.index') }}" class="text-xs text-slate-500 hover:underline py-2">Reset</a>
        @endif
        <div class="ml-auto">@include('erp._partials.per-page-select')</div>
    </form>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:1240px">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left font-black">Bundle</th>
                        <th class="px-3 py-3 text-left font-black">Isi<br>Paket</th>
                        <th class="px-3 py-3 text-right font-black">Waktu<br>/unit</th>
                        <th class="px-3 py-3 text-right font-black">HPP<br>Komponen</th>
                        <th class="px-3 py-3 text-right font-black">Overhead<br>Packing</th>
                        <th class="px-3 py-3 text-right font-black bg-teal-50">Packing<br>Khusus</th>
                        <th class="px-4 py-3 text-right font-black bg-slate-100 text-slate-600">HPP<br>/unit</th>
                        <th class="px-3 py-3 text-right font-black">Harga<br>Base</th>
                        <th class="px-5 py-3 text-right font-black">
                            Laba
                            <span class="block normal-case tracking-normal text-[10px] mt-0.5">
                                <span class="text-emerald-600">margin</span>
                                <span class="text-slate-300">/</span>
                                <span class="text-indigo-600">markup</span>
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($rows as $row)
                        @php
                            $pid    = $row['product']['id'];
                            $detail = route('analisa.hpp.bundle.show', array_merge([$pid], request()->query()));
                        @endphp
                        <tr class="hover:bg-blue-50/40">
                            <td class="px-5 py-3 cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                                @if(!empty($row['warnings']))
                                    <div class="text-[10px] font-semibold text-amber-600">{{ count($row['warnings']) }} catatan</div>
                                @endif
                            </td>

                            {{-- Isi paket ditampilkan langsung di baris: HPP bundle tidak bisa dinilai
                                 tanpa tahu isinya, dan isinya berubah tanpa jejak di halaman ini. --}}
                            <td class="px-3 py-3 cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                @forelse(array_slice($row['components'], 0, 3) as $c)
                                    <div class="text-xs text-slate-600 whitespace-nowrap">
                                        <span class="font-bold text-slate-700">{{ rtrim(rtrim(number_format($c['qty'], 2, ',', '.'), '0'), ',') }}&times;</span>
                                        {{ \Illuminate\Support\Str::limit($c['product']['name'], 28) }}
                                        @if($c['source'] === null)
                                            <span class="text-amber-600 font-bold">?</span>
                                        @elseif($c['source'] === 'kartu stok')
                                            <span class="text-[10px] text-slate-400">(kartu stok)</span>
                                        @endif
                                    </div>
                                @empty
                                    <div class="text-xs text-amber-600 font-semibold">belum ada komponen</div>
                                @endforelse
                                @if($row['component_count'] > 3)
                                    <div class="text-[10px] text-slate-400">+{{ $row['component_count'] - 3 }} komponen lagi</div>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-right whitespace-nowrap cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                <span class="font-semibold text-slate-800">{{ $jam($row['sec_per_unit']) }} jam</span>
                                <span class="block text-[10px] text-slate-400">jumlah komponen</span>
                            </td>

                            {{-- Satu angka, dengan penyusunnya di bawahnya: bundle tidak punya variable
                                 maupun fixed cost sendiri, keduanya warisan dari komponen. --}}
                            <td class="px-3 py-3 text-right tabular-nums cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                <span class="font-bold text-slate-800">{{ $rp($row['components_subtotal']) }}</span>
                                <span class="block text-[10px] text-slate-400 whitespace-nowrap">
                                    var {{ $rp($row['variable_cost']) }} · fix {{ $rp($row['fixed_cost']) }}
                                </span>
                                @if($row['component_packing_khusus'] > 0)
                                    <span class="block text-[10px] text-teal-600 whitespace-nowrap">
                                        + packing komponen {{ $rp($row['component_packing_khusus']) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-500 cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                {{ $rp($row['packing_overhead']) }}
                                <span class="block text-[10px] text-slate-400">sekali per paket</span>
                            </td>

                            {{-- Packing khusus milik bundle-nya sendiri: kardus paket / kotak hampers. --}}
                            <td class="px-3 py-3 text-right bg-teal-50/60 align-top">
                                <form method="POST" action="{{ route('analisa.hpp.packing-cost.save', $pid) }}" class="flex items-center justify-end gap-1">
                                    @csrf
                                    <input type="text" name="amount_per_unit" placeholder="0"
                                           value="{{ $row['packing_khusus'] !== null ? number_format($row['packing_khusus'], 0, ',', '.') : '' }}"
                                           title="{{ $packingNotes[$pid] ?? 'Biaya ekstra paketnya sendiri: kardus paket, kotak hampers' }}"
                                           class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-24 text-right">
                                    <button class="text-teal-700 hover:text-teal-900 text-xs font-bold">✓</button>
                                </form>
                            </td>

                            <td class="px-4 py-3 text-right bg-slate-100 tabular-nums cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                <span class="font-black text-slate-900">{{ $rp($row['hpp_per_unit']) }}</span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600 cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                {{ $row['base_price'] > 0 ? $rp($row['base_price']) : '—' }}
                                @if($row['bundle_discount'] !== null && $row['bundle_discount'] > 0)
                                    <span class="block text-[10px] text-slate-400 whitespace-nowrap"
                                          title="Harga komponen kalau dibeli satuan: {{ $rp($row['components_price_total']) }}">
                                        hemat {{ $rp($row['bundle_discount']) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right tabular-nums cursor-pointer align-top" onclick="window.location='{{ $detail }}'">
                                @if($row['margin_percent'] === null)
                                    <span class="text-slate-300">—</span>
                                @else
                                    <span class="block text-base font-black {{ $row['margin_percent'] < 0 ? 'text-red-600' : 'text-slate-800' }}">
                                        {{ $rp($row['margin']) }}
                                    </span>
                                    <span class="block mt-1 text-xs font-bold {{ $row['margin_percent'] < 0 ? 'text-red-600' : ($row['margin_percent'] < 20 ? 'text-amber-600' : 'text-emerald-600') }}"
                                          title="Margin = laba ÷ harga jual. Ukuran standar laporan keuangan; tidak pernah lewat 100%.">
                                        {{ $angka($row['margin_percent']) }}%
                                        <span class="font-semibold opacity-70">dari harga</span>
                                    </span>
                                    <span class="block text-xs font-bold text-indigo-600"
                                          title="Markup = laba ÷ HPP. Ukuran untuk menetapkan harga dari modal; bisa lewat 100%.">
                                        {{ $angka($row['markup_percent']) }}%
                                        <span class="font-semibold opacity-70">dari HPP</span>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-12 text-center text-slate-400">
                                Belum ada produk bertipe bundle. Produk dengan tipe jual <strong>bundle</strong> akan
                                muncul di sini beserta HPP rakitannya.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-xs text-slate-500 max-w-3xl">
            <div class="mb-1">
                <strong>Overhead packing dihitung sekali per paket, bukan per isi.</strong> Pembaginya jumlah surat
                jalan &mdash; satu bundle berisi tiga barang tetap dikirim sebagai satu paket. Karena itu yang diambil
                dari tiap komponen adalah HPP-nya tanpa overhead packing, lalu overhead ditambahkan sekali di sini.
            </div>
            <strong>Bundle tidak punya waktu produksi sendiri.</strong> Yang diproduksi komponennya; angka waktu di
            atas adalah jumlah waktu isi paket &mdash; untuk membaca kapasitas yang terpakai, bukan untuk menghitung
            fixed cost lagi.
        </div>
        {{ $rows->links() }}
    </div>
</div>
@endsection
