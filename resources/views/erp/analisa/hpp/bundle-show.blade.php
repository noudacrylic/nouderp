@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp ' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
    $jam   = fn (?float $detik) => $detik === null ? '—' : number_format($detik / 3600, 2, ',', '.');
    $qty   = fn (float $v) => rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');

    $p   = $data['product'];
    $pid = $p['id'];
@endphp

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">{{ $p['name'] }}</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                <span class="font-mono font-semibold text-blue-600">{{ $p['sku'] }}</span>
                · bundle berisi {{ $data['component_count'] }} komponen
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('inventory.products.setup.bundle', $pid) }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Komponen Bundle →</a>
            <a href="{{ route('analisa.hpp.bundle.index', request()->query()) }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">← Kembali</a>
        </div>
    </div>

    @foreach($data['warnings'] as $w)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-2.5 mb-3 text-sm">{{ $w }}</div>
    @endforeach

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Susunan: isi paket dulu, packing belakangan --}}
        <div class="lg:col-span-2 bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-800">Susunan HPP per Paket</h2>
                <p class="text-[11px] text-slate-500 mt-0.5">
                    HPP tiap komponen diambil dari tab Ready <strong>tanpa overhead packing</strong>-nya, lalu
                    overhead ditambahkan sekali di bawah.
                </p>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-2.5 text-left font-black">Komponen</th>
                        <th class="px-3 py-2.5 text-right font-black">Jumlah</th>
                        <th class="px-3 py-2.5 text-right font-black">HPP /unit</th>
                        <th class="px-5 py-2.5 text-right font-black">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($data['components'] as $c)
                        <tr>
                            <td class="px-5 py-3">
                                {{-- Ditautkan hanya kalau komponennya memang punya halaman HPP Ready;
                                     yang dari kartu stok tidak punya, tautannya akan mental balik. --}}
                                @if($c['source'] === 'HPP Ready')
                                    <a href="{{ route('analisa.hpp.show', $c['product']['id']) }}"
                                       class="font-semibold text-slate-800 hover:text-blue-600">{{ $c['product']['name'] }}</a>
                                @else
                                    <span class="font-semibold text-slate-800">{{ $c['product']['name'] }}</span>
                                @endif
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $c['product']['sku'] }}</div>
                                <div class="text-[11px] text-slate-500">
                                    var {{ $rp($c['variable_cost']) }} · fix {{ $rp($c['fixed_cost']) }}
                                    @if($c['packing_khusus'])
                                        · packing khusus {{ $rp($c['packing_khusus']) }}
                                    @endif
                                    @if($c['sec_per_unit'] !== null)
                                        · {{ $jam($c['sec_per_unit']) }} jam
                                    @endif
                                </div>
                                @if($c['note'])
                                    <div class="text-[11px] font-semibold {{ $c['source'] === null ? 'text-amber-600' : 'text-slate-400' }}">
                                        {{ $c['note'] }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums font-semibold text-slate-700">{{ $qty($c['qty']) }}&times;</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-700">{{ $rp($c['unit_cost']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums font-bold text-slate-800">{{ $rp($c['subtotal']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-8 text-center text-slate-400">
                                Bundle ini belum punya komponen.
                            </td>
                        </tr>
                    @endforelse

                    <tr class="bg-slate-50">
                        <td class="px-5 py-2.5 font-bold text-slate-700" colspan="3">Isi paket</td>
                        <td class="px-5 py-2.5 text-right tabular-nums font-black text-slate-800">{{ $rp($data['components_subtotal']) }}</td>
                    </tr>
                    <tr>
                        <td class="px-5 py-3" colspan="3">
                            <div class="font-semibold text-slate-800">Overhead Packing</div>
                            <div class="text-[11px] text-slate-500">
                                biaya packing sebulan ÷ jumlah surat jalan &mdash; sekali per paket, bukan per isi
                            </div>
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums font-bold text-teal-700">{{ $rp($data['packing_overhead']) }}</td>
                    </tr>
                    <tr>
                        <td class="px-5 py-3" colspan="3">
                            <div class="font-semibold text-slate-800">Packing Khusus Paket</div>
                            <div class="text-[11px] text-slate-500">
                                biaya ekstra paketnya sendiri: kardus paket, kotak hampers &mdash; diketik di
                                <a href="{{ route('analisa.hpp.bundle.index') }}" class="text-blue-600 font-semibold hover:underline">daftar bundle</a>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums font-bold text-teal-700">
                            {{ $data['packing_khusus'] === null ? '—' : $rp($data['packing_khusus']) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-800">
                        <td class="px-5 py-3.5 font-black text-white uppercase tracking-wider text-xs" colspan="3">HPP per Bundle</td>
                        <td class="px-5 py-3.5 text-right font-black text-white tabular-nums text-lg">{{ $rp($data['hpp_per_unit']) }}</td>
                    </tr>
                    @if($data['base_price'] > 0)
                        <tr class="bg-slate-50">
                            <td class="px-5 py-2.5 text-xs text-slate-600" colspan="3">Harga base</td>
                            <td class="px-5 py-2.5 text-right tabular-nums font-bold text-slate-700">{{ $rp($data['base_price']) }}</td>
                        </tr>
                        <tr class="bg-slate-50 border-t border-slate-200">
                            <td class="px-5 py-2.5" colspan="3">
                                <div class="text-sm font-bold text-slate-700">Laba per bundle</div>
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

        <div class="space-y-4">
            {{-- Bundling dibanding jual satuan --}}
            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-bold text-slate-800">Dibanding Jual Satuan</h2>
                    <p class="text-[11px] text-slate-500 mt-0.5">Potongan yang sebenarnya diberikan lewat bundling.</p>
                </div>
                <dl class="p-5 space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Harga komponen satuan</dt>
                        <dd class="font-semibold text-slate-800 tabular-nums">{{ $rp($data['components_price_total']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Harga bundle</dt>
                        <dd class="font-semibold text-slate-800 tabular-nums">{{ $data['base_price'] > 0 ? $rp($data['base_price']) : '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 border-t border-slate-100 pt-3">
                        <dt class="text-slate-700 font-bold">Potongan</dt>
                        <dd class="font-black tabular-nums {{ ($data['bundle_discount'] ?? 0) < 0 ? 'text-red-600' : 'text-emerald-600' }}">
                            {{ $data['bundle_discount'] === null ? '—' : $rp($data['bundle_discount']) }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Asal angka --}}
            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-bold text-slate-800">Asal Angka</h2>
                    <p class="text-[11px] text-slate-500 mt-0.5">Bundle tidak menghitung ulang apa pun.</p>
                </div>
                <dl class="p-5 space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Tarif fixed cost</dt>
                        <dd class="font-semibold text-slate-800 tabular-nums">{{ $rp($basis['rate_per_slot_hour']) }}/jam</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Waktu isi paket</dt>
                        <dd class="font-semibold text-slate-800 tabular-nums">{{ $jam($data['sec_per_unit']) }} jam</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Overhead packing</dt>
                        <dd class="font-semibold text-slate-800 tabular-nums">{{ $rp($basis['packing_per_transaction']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 border-t border-slate-100 pt-3">
                        <dt class="text-slate-500">Surat jalan/bulan</dt>
                        <dd class="font-semibold text-slate-800 tabular-nums">{{ $angka($basis['transactions_per_month'], 0) }}</dd>
                    </div>
                </dl>
                <p class="px-5 pb-5 text-[11px] text-slate-400 leading-relaxed">
                    Fixed cost sudah melekat di HPP tiap komponen &mdash; tidak dikalikan lagi di sini. Waktu di atas
                    hanya untuk membaca berapa kapasitas pabrik yang habis untuk satu paket.
                </p>
            </div>
        </div>
    </div>

    <p class="text-[11px] text-slate-400 mt-4 leading-relaxed">
        Angka di halaman ini <strong>analisa</strong>, bukan akuntansi &mdash; dipakai menetapkan harga dan melihat
        margin, dan tidak pernah masuk jurnal. Yang tercatat di akuntansi adalah biaya yang benar-benar terjadi saat
        komponennya dibuat dan dikirim.
    </p>
</div>
@endsection
