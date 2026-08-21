@extends('layouts.erp')

@php
    $rp = fn (?float $v) => 'Rp' . number_format((float) $v, 0, ',', '.');
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Simulasi Promo &mdash; {{ $channel['label'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                Rakit satu transaksi andaian: tambah produk, beri diskon, isi diskon ongkir, lalu lihat untungnya
                di atas HPP yang sudah terbentuk. Angka berubah sambil diketik &mdash; tidak ada yang disimpan
                dan tidak ada yang dijurnal.
            </p>
        </div>
        <a href="{{ route('analisa.harga.index', ['kanal' => $channel['key']]) }}"
           class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Tab Harga</a>
    </div>

    @include('erp.analisa._mode-asumsi')

    @include('erp.analisa.harga._promo-nav', ['mode' => 'transaksi'])

    @include('erp.analisa.harga._kanal')

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">

        {{-- Keranjang --}}
        <div class="xl:col-span-2 bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                <div class="text-sm font-bold text-slate-800">Isi Keranjang</div>
                <div class="flex gap-2">
                    <button type="button" id="btn-promo-aktif"
                            class="border border-emerald-300 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded-xl text-xs font-bold">
                        Pakai promo yang aktif
                    </button>
                    <button type="button" id="btn-tambah"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-xl text-xs font-bold">+ Produk</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="keranjang" style="min-width:820px">
                    <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-black">Produk</th>
                            <th class="px-2 py-2.5 text-center font-black w-20">Qty</th>
                            <th class="px-2 py-2.5 text-right font-black w-32">Harga</th>
                            <th class="px-2 py-2.5 text-right font-black w-24 bg-rose-50">Diskon %</th>
                            <th class="px-2 py-2.5 text-right font-black w-32 bg-rose-50">Diskon Rp</th>
                            <th class="px-3 py-2.5 text-right font-black w-32">Bersih</th>
                            <th class="px-3 py-2.5 text-right font-black w-32">HPP</th>
                            <th class="px-2 py-2.5 w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50" id="baris-keranjang"></tbody>
                </table>
            </div>

            <div class="px-5 py-3 text-[11px] text-slate-400 border-t border-slate-100">
                Diskon Rp berlaku untuk <strong>seluruh baris</strong> (bukan per unit), mengikuti cara Promosi
                menghitungnya. HPP dipakai apa adanya &mdash; ongkos membungkus dianggap sudah selesai dihitung di sana.
            </div>
        </div>

        {{-- Hasil --}}
        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 h-fit">
            <div class="text-sm font-bold text-slate-800 mb-3">Hitungan</div>

            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="tabular-nums font-semibold" id="v-subtotal">Rp0</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Diskon item</span><span class="tabular-nums text-rose-600" id="v-diskon-item">Rp0</span></div>

                <div class="flex justify-between items-center gap-2 pt-1">
                    <span class="text-slate-500">Diskon belanja</span>
                    <input type="text" id="i-diskon-belanja" value="0"
                           class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                </div>
                <div class="flex justify-between items-center gap-2">
                    <span class="text-slate-500" title="Ongkir yang Anda tanggung: gratis ongkir / potongan ongkir">Diskon ongkir</span>
                    <input type="text" id="i-diskon-ongkir" value="0"
                           class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                </div>

                {{-- Biaya admin: bawaannya potongan kanal ini, tapi bisa diubah untuk
                     mengandaikan "kalau subsidinya saya naikkan, masih untung tidak". --}}
                <div class="border-t border-slate-100 pt-2 mt-2">
                    <div class="flex justify-between items-center gap-2">
                        <span class="text-slate-500">Biaya admin (%)</span>
                        <input type="number" step="0.01" min="0" id="i-admin-persen" value="{{ $channel['fee']['percent'] }}"
                               class="border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                    </div>
                    <div class="flex justify-between items-center gap-2 mt-2">
                        <span class="text-slate-500">Biaya admin (Rp)</span>
                        <input type="text" id="i-admin-rp" value="{{ number_format($channel['fee']['fixed'], 0, ',', '.') }}"
                               class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
                    </div>
                    <div class="text-[10px] text-slate-400 mt-1">
                        Bawaan dari potongan {{ $channel['label'] }} ({{ $rp($channel['fee']['fixed']) }} + {{ rtrim(rtrim(number_format($channel['fee']['percent'], 2, ',', '.'), '0'), ',') }}%).
                        Ubah untuk mengandaikan biaya/subsidi yang lain.
                    </div>
                </div>

                <div class="flex justify-between border-t border-slate-100 pt-2 mt-2">
                    <span class="text-slate-500">Potongan admin</span><span class="tabular-nums text-rose-600" id="v-admin">Rp0</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Pendapatan bersih</span><span class="tabular-nums font-bold" id="v-pendapatan">Rp0</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">HPP</span><span class="tabular-nums" id="v-hpp">Rp0</span>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-slate-200">
                <div class="text-[11px] font-bold text-slate-400 uppercase">Untung</div>
                <div class="text-2xl font-black text-slate-900 tabular-nums" id="v-untung">Rp0</div>
                <div class="mt-1 text-xs font-bold">
                    <span class="text-emerald-600" id="v-margin">—</span> <span class="text-slate-400 font-semibold">dari harga</span>
                    <span class="text-slate-300 mx-1">·</span>
                    <span class="text-indigo-600" id="v-markup">—</span> <span class="text-slate-400 font-semibold">dari HPP</span>
                </div>
                <div class="text-[11px] text-amber-600 font-semibold mt-2 hidden" id="v-peringatan">
                    Transaksi ini rugi.
                </div>
                <div class="text-[11px] text-slate-400 mt-2 hidden" id="v-promo-nama"></div>
            </div>
        </div>
    </div>
</div>

{{-- Template satu baris keranjang. Produk dicari dengan mengetik — 233 produk terlalu
     banyak untuk digulir di dalam dropdown. --}}
<template id="tpl-baris">
    <tr class="align-middle" data-pid="" data-hpp="">
        <td class="px-4 py-2">
            {{-- Daftar hasilnya TIDAK ditaruh di sini: sel tabel ini berada di dalam kotak
                 ber-overflow, jadi apa pun yang menggantung ke bawah akan terpotong. Daftarnya
                 dirender sebagai satu lapisan di atas halaman (lihat _promo-hitung). --}}
            <input type="text" class="js-produk border border-slate-200 rounded-lg px-2 py-1 text-sm w-full"
                   placeholder="Ketik nama / SKU…" autocomplete="off">
        </td>
        <td class="px-2 py-2 text-center">
            <input type="number" min="1" step="1" value="1" class="js-qty border border-slate-200 rounded-lg px-2 py-1 text-sm w-16 text-center">
        </td>
        <td class="px-2 py-2 text-right">
            <input type="text" value="0" class="rupiah-input js-harga border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
        </td>
        <td class="px-2 py-2 text-right bg-rose-50/60">
            <input type="number" min="0" max="100" step="0.5" value="0" class="js-dpersen border border-slate-200 rounded-lg px-2 py-1 text-sm w-20 text-right">
        </td>
        <td class="px-2 py-2 text-right bg-rose-50/60">
            <input type="text" value="0" class="rupiah-input js-drupiah border border-slate-200 rounded-lg px-2 py-1 text-sm w-28 text-right">
        </td>
        <td class="px-3 py-2 text-right tabular-nums font-semibold js-bersih text-slate-700">Rp0</td>
        <td class="px-3 py-2 text-right tabular-nums js-hpp text-slate-500">Rp0</td>
        <td class="px-2 py-2 text-center">
            <button type="button" class="js-hapus text-slate-300 hover:text-red-500 font-bold">&times;</button>
        </td>
    </tr>
</template>

@include('erp.analisa.harga._promo-hitung')
@endsection
