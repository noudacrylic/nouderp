@extends('layouts.erp')

@php
    $rp    = fn (?float $v) => $v === null ? '—' : 'Rp' . number_format((float) $v, 0, ',', '.');
    $angka = fn (?float $v, int $d = 1) => $v === null ? '—' : number_format($v, $d, ',', '.');
@endphp

@section('content')
<div class="w-full px-6 py-4">

    @include('erp.analisa._hitung-ulang')

    <div class="flex flex-wrap justify-between items-start gap-3 mb-5">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Asumsi Harga Bahan</h1>
            <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
                &ldquo;Kalau akrilik naik jadi sekian, saya rugi tidak?&rdquo; Isi harga yang diandaikan, lalu buka
                <a href="{{ route('analisa.hpp.index', ['asumsi' => 1]) }}" class="text-blue-600 font-semibold hover:underline">HPP Ready</a> atau
                <a href="{{ route('analisa.harga.index', ['asumsi' => 1]) }}" class="text-blue-600 font-semibold hover:underline">Harga Produk</a>
                dalam mode asumsi. Yang muncul di sini hanya bahan <strong>beli</strong> &mdash; bahan setengah jadi
                buatan sendiri ikut naik dengan sendirinya lewat resepnya.
            </p>
        </div>
        <div class="flex gap-2">
            @if($aktif > 0)
                <a href="{{ route('analisa.hpp.index', ['asumsi' => 1]) }}"
                   class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-xl text-sm font-bold">Lihat HPP dgn asumsi</a>
                <form method="POST" action="{{ route('analisa.hpp.asumsi.clear') }}"
                      onsubmit="return confirm('Kosongkan seluruh asumsi harga bahan?')">
                    @csrf @method('DELETE')
                    <button class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">Kosongkan semua</button>
                </form>
            @endif
            <a href="{{ route('analisa.hpp.index') }}"
               class="border border-slate-200 text-slate-600 hover:bg-slate-50 px-4 py-2 rounded-xl text-sm font-semibold">HPP Ready</a>
        </div>
    </div>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm px-5 py-4 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-sm font-bold text-slate-800">
                    {{ $rows->count() }} bahan beli
                    @if($aktif > 0)
                        <span class="text-amber-600">· {{ $aktif }} sedang diandaikan</span>
                    @endif
                </div>
                <div class="text-[11px] text-slate-500 mt-0.5">
                    Harga acuannya harga beli terakhir di kartu stok. Kosongkan kolom asumsi untuk mengembalikan bahan ke harga itu.
                </div>
            </div>
            <form method="POST" action="{{ route('analisa.hpp.asumsi.bulk') }}" class="flex items-end gap-2">
                @csrf
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 mb-1">Naikkan semua bahan</label>
                    <div class="flex items-center gap-1">
                        <input type="number" step="0.5" name="percent" placeholder="10" required
                               class="border border-slate-200 rounded-xl px-3 py-2 text-sm w-24 text-right">
                        <span class="text-sm text-slate-400">%</span>
                    </div>
                </div>
                <button class="border border-amber-300 text-amber-700 hover:bg-amber-50 px-4 py-2 rounded-xl text-sm font-semibold">Terapkan ke semua</button>
            </form>
        </div>
    </div>

    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width:900px">
                <thead class="bg-slate-50 border-b border-slate-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left font-black">Bahan</th>
                        <th class="px-3 py-3 text-right font-black">Harga Beli<br>Terakhir</th>
                        <th class="px-3 py-3 text-right font-black bg-amber-50">Harga<br>Asumsi</th>
                        <th class="px-3 py-3 text-right font-black">Perubahan</th>
                        <th class="px-5 py-3 text-right font-black">Dipakai di</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($rows as $row)
                        <tr class="hover:bg-blue-50/40 align-top">
                            <td class="px-5 py-3">
                                <div class="font-semibold text-slate-800">{{ $row['product']['name'] }}</div>
                                <div class="text-xs font-mono font-semibold text-blue-600">{{ $row['product']['sku'] }}</div>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                <span class="font-bold text-slate-800">{{ $rp($row['price']) }}</span>
                                <span class="block text-[10px] text-slate-400">
                                    {{ $row['source'] === 'purchase' ? 'pembelian' : ($row['source'] ?? 'belum ada') }}
                                    {{ $row['price_at'] ? \Carbon\Carbon::parse($row['price_at'])->translatedFormat('d M Y') : '' }}
                                </span>
                            </td>

                            <td class="px-3 py-3 text-right bg-amber-50/60">
                                <form method="POST" action="{{ route('analisa.hpp.asumsi.save') }}" class="flex items-center justify-end gap-1">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $row['product']['id'] }}">
                                    <input type="text" name="price" placeholder="{{ $row['price'] ? number_format($row['price'], 0, ',', '.') : '0' }}"
                                           value="{{ $row['assumed'] !== null ? number_format($row['assumed'], 0, ',', '.') : '' }}"
                                           class="rupiah-input border border-slate-200 rounded-lg px-2 py-1 text-sm w-32 text-right">
                                    <button class="text-amber-700 hover:text-amber-900 text-xs font-bold" title="Simpan asumsi">✓</button>
                                </form>
                            </td>

                            <td class="px-3 py-3 text-right tabular-nums">
                                @if($row['change'] === null)
                                    <span class="text-slate-300">—</span>
                                @else
                                    <span class="text-sm font-black {{ $row['change'] > 0 ? 'text-red-600' : ($row['change'] < 0 ? 'text-emerald-600' : 'text-slate-400') }}">
                                        {{ $row['change'] > 0 ? '+' : '' }}{{ $angka($row['change']) }}%
                                    </span>
                                    <span class="block text-[10px] text-slate-400">
                                        {{ $row['change'] > 0 ? '+' : '' }}{{ $rp($row['assumed'] - $row['price']) }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3 text-right">
                                <span class="text-sm font-bold text-slate-700">{{ $row['used_by'] }}</span>
                                <span class="block text-[10px] text-slate-400">produk jadi</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-slate-400">
                                Belum ada bahan yang bisa diandaikan. Bahan muncul di sini setelah dipakai di OP yang
                                jadi sampel HPP.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 text-xs text-slate-500 max-w-3xl leading-relaxed">
        <div class="mb-1">
            <strong>Takaran bahannya dari OP yang benar-benar dikerjakan</strong>, bukan dari BOM &mdash; jadi susut
            dan bahan tambahan ikut terhitung, sama seperti waktu dan biaya di halaman HPP.
        </div>
        <strong>Penelusurannya berjenjang.</strong> Akrilik lembaran &rarr; bahan setengah jadi &rarr; produk jadi:
        menaikkan harga lembaran ikut menaikkan bahan setengah jadinya, lalu produk jadinya. Karena itu bahan
        setengah jadi tidak punya baris sendiri di sini.
    </div>
</div>
@endsection
