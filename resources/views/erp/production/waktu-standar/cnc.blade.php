@extends('layouts.erp')

@php
    use App\Modules\Production\Services\PenilaianKinerjaService as PK;
    $pct = fn ($a, $b) => $b ? number_format($a / $b * 100, 0, ',', '.') . '%' : '—';
@endphp

@section('content')
<div class="w-full max-w-6xl px-6 py-4 space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-800">Penilaian Operator CNC</h1>
        <p class="text-xs text-gray-500">Tahap 0 — dari data timer ERP saja, belum ada sinyal dari mesin. Informasi untuk owner, belum masuk nilai.</p>
    </div>

    <form method="GET" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Operator</label>
            <select name="orang" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-1.5 min-w-[14rem]">
                @foreach($semua as $o)
                    <option value="{{ $o->id }}" @selected($operator && $o->id === $operator->id)>{{ $o->name }}{{ $o->is_active ? '' : ' (nonaktif)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Bulan</label>
            <input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" onchange="this.form.submit()" class="border border-gray-200 rounded-xl px-3 py-1.5">
        </div>
        <noscript><button class="px-4 py-1.5 rounded-xl border border-gray-300">Tampilkan</button></noscript>
    </form>

    @if(!$hasil)
        <div class="bg-white rounded-2xl border border-gray-100 p-4 text-sm text-gray-500">Belum ada operator yang menaungi mesin (atur di Divisi → pelaksana induk).</div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="text-lg font-bold text-gray-800">{{ $operator->name }}</div>
                <div class="text-sm text-gray-500">{{ $bulan->translatedFormat('F Y') }}
                    @if($berjalan)<span class="text-amber-700">· bulan berjalan, s/d {{ $hasil['sampai']->format('d/m H:i') }}</span>@endif
                    · jam kerja tercatat {{ PK::teksMenit($hasil['jam_kerja']) }}
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2 max-w-4xl">
                <b>Diam saat ada antrean</b> = mesin tidak punya langkah berjalan (sesudah Selesai, belum Mulai berikutnya), di jam kerja
                {{ $operator->name }} (sudah scan masuk, bukan istirahat, lembur tidak dihitung), padahal ada OP CNC yang siap dikerjakan.
                Jeda di bawah {{ $hasil['ambang'] }} menit tidak didaftar. Belum terlihat: mesin selesai memotong tapi tombol Selesai belum
                ditekan — itu menunggu sinyal dari mesin (Tahap 1–2).
            </p>

            <div class="overflow-x-auto">
            <table class="w-full text-sm mt-3">
                <thead class="text-xs text-gray-400 border-b border-gray-100">
                    <tr>
                        <th class="text-left font-normal py-1">Mesin</th>
                        <th class="text-right font-normal">Bekerja</th>
                        <th class="text-right font-normal">Diam saat ada antrean</th>
                        <th class="text-right font-normal">Diam, antrean kosong</th>
                        <th class="text-right font-normal">Langkah selesai</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($hasil['mesin'] as $m)
                    <tr class="border-b border-gray-50">
                        <td class="py-2 font-semibold"><a href="#mesin-{{ $m['id'] }}" class="hover:underline">{{ $m['nama'] }}</a></td>
                        <td class="text-right">{{ PK::teksMenit($m['jalan']) }} <span class="text-xs text-gray-400">{{ $pct($m['jalan'], $hasil['jam_kerja']) }}</span></td>
                        <td class="text-right font-bold {{ $m['diam_antrean'] ? 'text-red-600' : 'text-gray-400' }}">
                            {{ $m['diam_antrean'] ? PK::teksMenit($m['diam_antrean']) : '—' }}
                            <span class="text-xs font-normal text-gray-400">{{ $pct($m['diam_antrean'], $hasil['jam_kerja']) }}</span>
                        </td>
                        <td class="text-right text-gray-500">{{ $m['diam_kosong'] ? PK::teksMenit($m['diam_kosong']) : '—' }}</td>
                        <td class="text-right">{{ $m['langkah'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>

        @foreach($hasil['mesin'] as $m)
            <div id="mesin-{{ $m['id'] }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <h2 class="font-bold text-gray-800">{{ $m['nama'] }} — {{ count($m['jeda']) }} kali diam saat ada antrean</h2>
                @if(!$m['jeda'])
                    <p class="text-sm text-gray-400 mt-1">Tidak ada jeda ≥ {{ $hasil['ambang'] }} menit.</p>
                @else
                    <div class="overflow-x-auto">
                    <table class="w-full text-xs mt-2">
                        <thead class="text-gray-400">
                            <tr>
                                <th class="text-left font-normal py-1">Diam sejak</th>
                                <th class="text-left font-normal">Sampai</th>
                                <th class="text-right font-normal">Diam saat antrean</th>
                                <th class="text-left font-normal pl-3">Sesudah OP</th>
                                <th class="text-left font-normal">Lanjut OP</th>
                                <th class="text-left font-normal">OP menunggu saat itu</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($m['jeda'] as $j)
                            <tr class="border-t border-gray-100 align-top">
                                <td class="py-1.5 whitespace-nowrap">{{ $j['dari']->translatedFormat('D d/m H:i') }}</td>
                                <td class="whitespace-nowrap">{{ $j['sampai'] ? $j['sampai']->translatedFormat($j['sampai']->isSameDay($j['dari']) ? 'H:i' : 'D d/m H:i') : 'masih diam' }}</td>
                                <td class="text-right font-bold whitespace-nowrap">{{ PK::teksMenit($j['menit']) }}</td>
                                <td class="pl-3 whitespace-nowrap">
                                    @if($j['sebelum'])<a href="{{ route('production.orders.show', $j['sebelum']['op_id']) }}" class="text-blue-700 hover:underline">{{ $j['sebelum']['op'] }}</a>@else — @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if($j['sesudah'])<a href="{{ route('production.orders.show', $j['sesudah']['op_id']) }}" class="text-blue-700 hover:underline">{{ $j['sesudah']['op'] }}</a>@else — @endif
                                </td>
                                <td class="text-gray-500">
                                    @foreach($j['menunggu'] as $w)
                                        <a href="{{ route('production.orders.show', $w['op_id']) }}" class="hover:underline">{{ $w['op'] }}</a>@if(!$loop->last), @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
@endsection
