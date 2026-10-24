@extends('layouts.erp')

@section('content')
@php
    $warnaMode = [
        'nonaktif'    => 'bg-gray-100 text-gray-500',
        'bayangan'    => 'bg-amber-100 text-amber-800',
        'persetujuan' => 'bg-blue-100 text-blue-800',
        'otomatis'    => 'bg-green-100 text-green-800',
    ];
    $penjelasanMode = [
        'nonaktif'    => 'Tidak dijalankan.',
        'bayangan'    => 'Jalan di data asli tapi tidak bertindak — hanya mencatat apa yang AKAN dikerjakan.',
        'persetujuan' => 'Membuat usulan untuk disetujui dulu.',
        'otomatis'    => 'Langsung bertindak.',
    ];
    $status = [
        'dibuat'      => ['✅', 'Dibuat',              'text-green-700'],
        'akan_dibuat' => ['👻', 'Akan dibuat (bayangan)', 'text-amber-700'],
        'gagal'       => ['⚠️', 'Gagal',               'text-red-700'],
        'dilewati'    => ['➖', 'Dilewati',            'text-gray-500'],
    ];
    $labelPemicu = ['jadwal' => '⏱ Jadwal', 'manual' => '👆 Manual', 'peristiwa' => '⚡ Peristiwa'];
@endphp
<div class="w-full px-6 py-4 max-w-6xl">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <span class="text-4xl">{{ $karyawan['ikon'] }}</span>
            <div>
                <h1 class="text-xl font-bold text-slate-800">{{ $karyawan['nama'] }}</h1>
                <p class="text-xs text-slate-500">Karyawan virtual · Divisi {{ $karyawan['divisi'] }}</p>
                <p class="text-sm text-slate-600 mt-1 max-w-3xl">{{ $karyawan['deskripsi'] }}</p>
            </div>
        </div>
        <a href="{{ route('karyawan-virtual.index') }}" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded text-sm">Kembali</a>
    </div>

    {{-- Skill --}}
    <h2 class="font-semibold text-slate-800 mb-2">Skill</h2>
    <div class="grid md:grid-cols-2 gap-4 mb-6">
        @foreach ($skills as $s)
            @php $skill = $s['skill']; $state = $s['state']; @endphp
            <div class="bg-white rounded shadow p-4 flex flex-col" x-data="{ rinci: false }">
                <div class="flex items-start justify-between gap-2 mb-1">
                    <div class="font-semibold text-slate-800">{{ $skill->label() }}</div>
                    <span class="shrink-0 rounded px-2 py-0.5 text-xs font-semibold {{ $warnaMode[$s['mode']] ?? '' }}">{{ $labelMode[$s['mode']] ?? $s['mode'] }}</span>
                </div>
                <p class="text-xs text-slate-600 mb-1">{{ $skill->deskripsi() }}</p>
                <p class="text-[11px] text-slate-400 mb-3">Bekerja: {{ $skill->jadwal() }}</p>

                @if (count($skill->modeTersedia()) > 1)
                    @if ($bolehAtur)
                        <form method="POST" action="{{ route('karyawan-virtual.mode', [$kode, $s['key']]) }}" class="flex flex-wrap gap-1 mb-1">
                            @csrf
                            @foreach ($skill->modeTersedia() as $m)
                                <button type="submit" name="mode" value="{{ $m }}" title="{{ $penjelasanMode[$m] ?? '' }}"
                                        class="text-xs px-2 py-1 rounded border {{ $s['mode'] === $m ? 'border-slate-700 bg-slate-700 text-white' : 'border-gray-300 text-gray-600 hover:bg-gray-50' }}">
                                    {{ $labelMode[$m] ?? $m }}
                                </button>
                            @endforeach
                        </form>
                    @endif
                    <p class="text-[11px] text-slate-500 mb-3">
                        {{ $penjelasanMode[$s['mode']] ?? '' }}
                        @if ($state->mode_diubah_at)
                            · diubah {{ $state->pengubahMode?->name ?? '—' }}, {{ $state->mode_diubah_at->format('d/m/Y H:i') }}
                        @endif
                    </p>
                @elseif ($skill->alasanTerkunci())
                    <p class="text-[11px] text-slate-500 mb-3">🔒 {{ $skill->alasanTerkunci() }}</p>
                @endif

                <div class="mt-auto border-t border-slate-100 pt-2 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-slate-500">
                            @if ($state->terakhir_jalan_at)
                                Pemeriksaan terakhir {{ $state->terakhir_jalan_at->format('d/m H:i') }}: {{ $state->ringkasan_terakhir }}
                                @if (!empty($state->rincian_terakhir))
                                    <button type="button" @click="rinci = !rinci" class="text-blue-600 underline ml-1"
                                            x-text="rinci ? 'tutup' : 'rincian'">rincian</button>
                                @endif
                            @else
                                Belum pernah diperiksa.
                            @endif
                        </div>
                        @if ($s['mode'] !== 'nonaktif')
                            <form method="POST" action="{{ route('karyawan-virtual.jalankan', [$kode, $s['key']]) }}"
                                  @if ($s['mode'] === 'otomatis') onsubmit="return confirm('Jalankan sekarang? Mode Otomatis — order produksi bisa langsung dibuat.')" @endif>
                                @csrf
                                <button type="submit" class="border border-blue-300 text-blue-700 hover:bg-blue-50 px-2 py-1 rounded font-semibold">▶ Jalankan sekarang</button>
                            </form>
                        @endif
                    </div>
                    @if (!empty($state->rincian_terakhir))
                        <ul x-show="rinci" x-cloak class="mt-2 space-y-1 max-h-64 overflow-y-auto">
                            @foreach ($state->rincian_terakhir as $r)
                                @php [$ikon, $label, $warna] = $status[$r['status']] ?? ['•', '', '']; @endphp
                                <li class="{{ $warna }}" title="{{ $label }}">
                                    {{ $ikon }} <b>{{ $r['judul'] }}</b>
                                    <span class="text-slate-500">— {{ $r['alasan'] }}</span>
                                    @if ($r['tautan'] ?? null)
                                        <a href="{{ $r['tautan'] }}" class="text-blue-600 underline">{{ $r['dokumen'] }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Jurnal kerja --}}
    <h2 class="font-semibold text-slate-800 mb-2">Jurnal Kerja</h2>
    <form method="GET" class="bg-white rounded shadow p-3 mb-3 flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Skill</label>
            <select name="skill" class="filter-auto border rounded px-2 py-1.5">
                <option value="">Semua skill</option>
                @foreach ($labelSkill as $key => $label)
                    <option value="{{ $key }}" @selected(request('skill') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tampilkan</label>
            <select name="tampil" class="filter-auto border rounded px-2 py-1.5">
                <option value="">Yang ada tindakannya</option>
                <option value="semua" @selected(request('tampil') === 'semua')>Semua (termasuk jalan manual tanpa tindakan)</option>
            </select>
        </div>
        @include('erp._partials.per-page-select')
    </form>

    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="text-left px-3 py-2">Waktu</th>
                    <th class="text-left px-3 py-2">Skill</th>
                    <th class="text-left px-3 py-2">Pemicu</th>
                    <th class="text-left px-3 py-2">Mode</th>
                    <th class="text-left px-3 py-2">Ringkasan</th>
                </tr>
            </thead>
            @forelse ($jurnal as $j)
                <tbody x-data="{ buka: false }" class="border-t border-gray-100">
                    <tr class="hover:bg-gray-50 cursor-pointer align-top" @click="buka = !buka">
                        <td class="px-3 py-2 whitespace-nowrap text-gray-600">{{ $j->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2">{{ $labelSkill[$j->skill] ?? $j->skill }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600">
                            {{ $labelPemicu[$j->pemicu] ?? $j->pemicu }}
                            @if ($j->keterangan_pemicu)<div class="text-gray-400">{{ $j->keterangan_pemicu }}</div>@endif
                            @if ($j->user)<div class="text-gray-400">oleh {{ $j->user->name }}</div>@endif
                        </td>
                        <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $warnaMode[$j->mode] ?? '' }}">{{ $labelMode[$j->mode] ?? $j->mode }}</span></td>
                        <td class="px-3 py-2">
                            {{ $j->ringkasan }}
                            <span class="text-xs text-blue-600 ml-1" x-text="buka ? '▲' : '▼'">▼</span>
                        </td>
                    </tr>
                    <tr x-show="buka" x-cloak>
                        <td colspan="5" class="px-3 pb-3">
                            <ul class="text-xs space-y-1 bg-gray-50 rounded p-2">
                                @foreach ($j->rincian as $r)
                                    @php [$ikon, $label, $warna] = $status[$r['status']] ?? ['•', $r['status'], '']; @endphp
                                    <li class="{{ $warna }}" title="{{ $label }}">
                                        {{ $ikon }} <b>{{ $r['judul'] }}</b>
                                        <span class="text-slate-500">— {{ $r['alasan'] }}</span>
                                        @if ($r['tautan'] ?? null)
                                            <a href="{{ $r['tautan'] }}" class="text-blue-600 underline" @click.stop>{{ $r['dokumen'] }}</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </td>
                    </tr>
                </tbody>
            @empty
                <tbody>
                    <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada jurnal kerja.</td></tr>
                </tbody>
            @endforelse
        </table>
    </div>
    <div class="mt-3">{{ $jurnal->links() }}</div>
</div>
@endsection
