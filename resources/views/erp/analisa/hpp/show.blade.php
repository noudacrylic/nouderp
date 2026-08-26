@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp ' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $jam   = fn (?float $detik) => $detik === null ? '—' : number_format($detik / 3600, 2, ',', '.');

    $p    = $data['product'];
    $hpp  = $data['hpp_per_unit'];
    $suku = [
        ['Variable Cost',    $data['variable_cost'],    'dari WIP — lapisan stok terbaru hasil OP sampel',            'slate'],
        ['Fixed Cost',       $data['fixed_cost'],       'tarif per slot-jam × waktu produksi per unit',                'blue'],
        ['Overhead Packing', $data['packing_overhead'], 'biaya packing sebulan ÷ jumlah surat jalan',                  'teal'],
        ['Packing Khusus',   $data['packing_khusus'],   'biaya ekstra yang diketik sendiri: peti kayu, kardus khusus', 'teal'],
    ];
@endphp

@section('content')
<div class="w-full px-6 py-4">

    @include('erp.analisa._hitung-ulang')

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">{{ $p['name'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                <span class="font-mono font-semibold text-blue-600">{{ $p['sku'] }}</span>
                · rincian HPP dari {{ $data['sample_count'] }} sampel OP
                @if($data['has_assumption'])
                    · <span class="text-indigo-600 font-semibold">memakai asumsi waktu</span>
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('analisa.waktu-produksi.show', $p['id']) }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Waktu Produksi →</a>
            <a href="{{ route('analisa.hpp.index', request()->query()) }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">← Kembali</a>
        </div>
    </div>

    @foreach($data['warnings'] as $w)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-2.5 mb-3 text-sm">{{ $w }}</div>
    @endforeach

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Susunan HPP --}}
        <div class="lg:col-span-2 bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-800">Susunan HPP per Unit</h2>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-50">
                    @foreach($suku as [$nama, $nilai, $ket, $warna])
                        <tr>
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-800">{{ $nama }}</div>
                                <div class="text-[11px] text-slate-500">{{ $ket }}</div>
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums font-bold text-{{ $warna }}-700 whitespace-nowrap">
                                {{ $nilai === null ? '—' : $rp($nilai) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-800">
                        <td class="px-5 py-3.5 font-black text-white uppercase tracking-wider text-xs">HPP per Unit</td>
                        <td class="px-5 py-3.5 text-right font-black text-white tabular-nums text-lg">{{ $rp($hpp) }}</td>
                    </tr>
                    @if($data['base_price'] > 0)
                        <tr class="bg-slate-50">
                            <td class="px-5 py-2.5 text-xs text-slate-600">Harga base</td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-bold text-slate-700">{{ $rp($data['base_price']) }}</td>
                        </tr>
                        <tr class="bg-slate-50 border-t border-slate-200">
                            <td class="px-5 py-2.5">
                                <div class="text-sm font-bold text-slate-700">Laba per unit</div>
                                <div class="text-[11px] text-slate-500 mt-0.5 leading-relaxed">
                                    <span class="text-emerald-600 font-bold">Margin</span> = laba ÷ harga jual, ukuran
                                    standar laporan keuangan.<br>
                                    <span class="text-indigo-600 font-bold">Markup</span> = laba ÷ HPP, berapa kali
                                    lipat modalnya.
                                </div>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums">
                                <span class="block text-lg font-black {{ $data['margin_percent'] < 0 ? 'text-red-600' : 'text-slate-800' }}">
                                    {{ $rp($data['margin']) }}
                                </span>
                                <span class="block mt-1 text-sm font-black {{ $data['margin_percent'] < 0 ? 'text-red-600' : ($data['margin_percent'] < 20 ? 'text-amber-600' : 'text-emerald-600') }}">
                                    {{ $angka($data['margin_percent']) }}%
                                    <span class="text-[11px] font-semibold opacity-70">margin</span>
                                </span>
                                <span class="block text-sm font-black text-indigo-600">
                                    {{ $angka($data['markup_percent']) }}%
                                    <span class="text-[11px] font-semibold opacity-70">markup</span>
                                </span>
                            </td>
                        </tr>
                    @endif
                </tfoot>
            </table>
        </div>

        {{-- Cara Fixed Cost dihitung --}}
        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-800">Asal Fixed Cost</h2>
                <p class="text-[11px] text-slate-500 mt-0.5">Satu tarif untuk semua divisi.</p>
            </div>
            <dl class="p-5 space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Fixed cost sebulan</dt>
                    <dd class="font-semibold text-slate-800 tabular-nums">{{ $rp($basis['fixed_total']) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Kapasitas tersedia</dt>
                    <dd class="font-semibold text-slate-800 tabular-nums">{{ $angka($basis['available_hours'], 0) }} slot-jam</dd>
                </div>
                <div class="flex justify-between gap-3 border-t border-slate-100 pt-3">
                    <dt class="text-slate-600 font-semibold">Tarif</dt>
                    <dd class="font-black text-blue-700 tabular-nums">{{ $rp($basis['rate_per_slot_hour']) }}/jam</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Waktu produk ini</dt>
                    <dd class="font-semibold text-slate-800 tabular-nums">{{ $jam($data['sec_per_unit_effective']) }} jam</dd>
                </div>
                <div class="flex justify-between gap-3 border-t border-slate-100 pt-3">
                    <dt class="text-slate-700 font-bold">Fixed cost / unit</dt>
                    <dd class="font-black text-blue-700 tabular-nums">{{ $rp($data['fixed_cost']) }}</dd>
                </div>
            </dl>

            <div class="px-5 pb-5">
                <div class="text-[11px] font-bold text-slate-400 uppercase mb-2">Waktu per divisi</div>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-slate-50">
                        @foreach($data['per_division'] as $cell)
                            <tr>
                                <td class="py-1.5 text-slate-600">
                                    {{ $cell['department']['name'] ?? '—' }}
                                    @if($cell['use_assumption'])
                                        <span class="text-[10px] font-bold text-indigo-600">asumsi</span>
                                    @endif
                                </td>
                                <td class="py-1.5 text-right tabular-nums font-semibold text-slate-800">
                                    {{ $jam($cell['sec_per_unit_effective'] ?? null) }} jam
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Rekonsiliasi --}}
    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm px-5 py-4 mt-4">
        <div class="flex flex-wrap items-center justify-between gap-5">
            <div>
                <div class="text-sm font-bold text-slate-800">Rekonsiliasi Fixed Cost</div>
                <p class="text-[11px] text-slate-500 mt-0.5">
                    Terserap + tidak terserap = fixed cost sebulan. Kalau meleset, waktu per unit dan kuota
                    diambil dari data yang tidak sama.
                </p>
            </div>
            <div class="flex flex-wrap gap-6 text-right">
                <div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase">Terserap</div>
                    <div class="text-lg font-black text-emerald-600">{{ $rp($basis['absorbed']) }}</div>
                </div>
                <div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase">Tidak terserap</div>
                    <div class="text-lg font-black text-amber-600">{{ $rp($basis['unabsorbed']) }}</div>
                    <div class="text-[10px] text-slate-400">{{ $angka($basis['unabsorbed_percent']) }}% — kapasitas menganggur</div>
                </div>
                <div class="border-l border-slate-100 pl-6">
                    <div class="text-[11px] font-bold text-slate-400 uppercase">Total</div>
                    <div class="text-lg font-black text-slate-800">{{ $rp($basis['fixed_total']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <p class="text-[11px] text-slate-400 mt-4 leading-relaxed">
        Angka di halaman ini <strong>analisa</strong>, bukan akuntansi — dipakai menetapkan harga dan melihat margin,
        dan tidak pernah masuk jurnal. Yang tercatat di akuntansi adalah biaya yang benar-benar terjadi, apa adanya.
    </p>
</div>
@endsection
