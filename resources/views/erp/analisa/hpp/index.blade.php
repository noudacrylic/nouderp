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
            <h1 class="text-xl font-bold text-slate-800">HPP Produk</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Variable Cost + Fixed Cost + Overhead Packing + Packing Khusus.
                <strong>Angka analisa</strong> untuk menetapkan harga &mdash; tidak pernah dijurnal.
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('analisa.kuota.index') }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Kuota Produksi</a>
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
            <label class="block text-xs font-bold text-slate-500 mb-1">Cari Produk</label>
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
            <a href="{{ route('analisa.hpp.index') }}" class="text-xs text-slate-500 hover:underline py-2">Reset</a>
        @endif
        <div class="ml-auto">@include('erp._partials.per-page-select')</div>
    </form>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:1180px">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left font-black">Produk</th>
                        <th class="px-3 py-3 text-right font-black">Waktu<br>/unit</th>
                        <th class="px-3 py-3 text-right font-black">Variable<br>Cost</th>
                        <th class="px-3 py-3 text-right font-black">Fixed<br>Cost</th>
                        <th class="px-3 py-3 text-right font-black">Overhead<br>Packing</th>
                        <th class="px-3 py-3 text-right font-black bg-teal-50">Packing<br>Khusus</th>
                        <th class="px-4 py-3 text-right font-black bg-slate-100 text-slate-600">HPP<br>/unit</th>
                        <th class="px-3 py-3 text-right font-black">Harga<br>Base</th>
                        {{-- Warna label di kepala kolom sengaja sama dengan warna angkanya di
                             bawah, supaya tidak perlu membaca keterangan untuk tahu mana yang mana. --}}
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
                            $detail = route('analisa.hpp.show', array_merge([$pid], request()->query()));
                        @endphp
                        <tr class="hover:bg-blue-50/40 {{ $row['has_assumption'] ? 'bg-indigo-50/30' : '' }}">
                            <td class="px-5 py-3 cursor-pointer" onclick="window.location='{{ $detail }}'">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                                @if($row['has_assumption'])
                                    <div class="text-[10px] font-bold text-indigo-600">pakai asumsi waktu</div>
                                @endif
                                @if(!empty($row['warnings']))
                                    <div class="text-[10px] font-semibold text-amber-600">{{ count($row['warnings']) }} catatan</div>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-right whitespace-nowrap cursor-pointer" onclick="window.location='{{ $detail }}'">
                                <span class="font-semibold text-slate-800">{{ $jam($row['sec_per_unit_effective']) }} jam</span>
                                @if($row['capacity_per_day'] !== null)
                                    <span class="block text-[10px] text-slate-400">{{ $angka($row['capacity_per_day']) }} pcs/hari</span>
                                @endif
                            </td>

                            {{-- Tiga angka bersanding: yang dipakai (tebal), lalu dua pembanding —
                                 harga bahan hari ini dan harga andaian. Selisih "hari ini" terhadap
                                 yang tercatat memberi tahu seberapa basi lapisan stok sampelnya. --}}
                            <td class="px-3 py-3 text-right tabular-nums cursor-pointer {{ $row['variable_cost'] === null ? 'text-amber-600' : 'text-slate-700' }}"
                                onclick="window.location='{{ $detail }}'">
                                <span class="font-bold {{ request()->boolean('asumsi') ? 'text-amber-700' : '' }}">
                                    {{ $row['variable_cost'] === null ? 'belum ada' : $rp($row['variable_cost']) }}
                                </span>
                                @if($row['variable_today'] !== null)
                                    <span class="block text-[10px] text-slate-400 whitespace-nowrap"
                                          title="Resep bahan dinilai dengan harga beli terakhir tiap bahan">
                                        hari ini {{ $rp($row['variable_today']) }}
                                    </span>
                                @endif
                                @if($row['variable_assumed'] !== null && $row['variable_assumed'] != $row['variable_today'])
                                    <span class="block text-[10px] font-bold text-amber-600 whitespace-nowrap"
                                          title="Resep bahan dinilai dengan harga yang diandaikan di sub-tab Asumsi Bahan">
                                        asumsi {{ $rp($row['variable_assumed']) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-700 cursor-pointer" onclick="window.location='{{ $detail }}'">
                                {{ $rp($row['fixed_cost']) }}
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-500 cursor-pointer" onclick="window.location='{{ $detail }}'">
                                {{ $rp($row['packing_overhead']) }}
                            </td>

                            {{-- Inline edit: biaya EKSTRA di atas overhead, bukan penggantinya. --}}
                            <td class="px-3 py-3 text-right bg-teal-50/60">
                                <form method="POST" action="{{ route('analisa.hpp.packing-cost.save', $pid) }}" class="flex items-center justify-end gap-1">
                                    @csrf
                                    <input type="text" name="amount_per_unit" placeholder="0"
                                           value="{{ $row['packing_khusus'] !== null ? number_format($row['packing_khusus'], 0, ',', '.') : '' }}"
                                           title="{{ $packingNotes[$pid] ?? 'Biaya ekstra: peti kayu, kardus khusus' }}"
                                           class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-24 text-right">
                                    <button class="text-teal-700 hover:text-teal-900 text-xs font-bold">✓</button>
                                </form>
                            </td>

                            <td class="px-4 py-3 text-right bg-slate-100 tabular-nums cursor-pointer" onclick="window.location='{{ $detail }}'">
                                <span class="font-black text-slate-900">{{ $rp($row['hpp_per_unit']) }}</span>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums text-slate-600 cursor-pointer" onclick="window.location='{{ $detail }}'">
                                {{ $row['base_price'] > 0 ? $rp($row['base_price']) : '—' }}
                            </td>

                            <td class="px-5 py-3 text-right tabular-nums cursor-pointer" onclick="window.location='{{ $detail }}'">
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
                                Belum ada produk dengan data waktu produksi untuk filter ini.
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
                <strong>Dua ukuran keuntungan, keduanya benar.</strong>
                <span class="text-emerald-600 font-bold">Hijau — margin</span> = laba ÷ harga jual: ukuran standar
                laporan keuangan, sebanding dengan Laba Kotor di Laba Rugi, tidak pernah lewat 100%.
                <span class="text-indigo-600 font-bold">Ungu — markup</span> = laba ÷ HPP: berapa kali lipat modalnya,
                dipakai saat menetapkan harga. Margin 50% = markup 100% = harga 2× HPP.
            </div>
            <strong>Packing Khusus</strong> ditambahkan di atas overhead packing &mdash; peti kayu dan kardus khusus
            adalah biaya ekstra, bukan pengganti ongkos membungkus yang biasa. Kosongkan untuk menghapusnya.
        </div>
        {{ $rows->links() }}
    </div>
</div>
@endsection
