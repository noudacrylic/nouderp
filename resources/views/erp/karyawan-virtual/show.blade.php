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
        'persetujuan' => 'Membuat usulan; dikerjakan setelah disetujui lewat Telegram atau halaman ini.',
        'otomatis'    => 'Langsung bertindak.',
    ];
    $status = [
        'dibuat'      => ['✅', 'Dibuat',              'text-green-700'],
        'diubah'      => ['✏️', 'Diubah',              'text-green-700'],
        'akan_dibuat' => ['👻', 'Akan dibuat (bayangan)', 'text-amber-700'],
        'gagal'       => ['⚠️', 'Gagal',               'text-red-700'],
        'diusulkan'   => ['🙋', 'Diusulkan',           'text-blue-700'],
        'menunggu'    => ['⏳', 'Menunggu persetujuan', 'text-blue-600'],
        'ditolak'     => ['❌', 'Ditolak',             'text-red-700'],
        'dilewati'    => ['➖', 'Dilewati',            'text-gray-500'],
    ];
    $labelPemicu = ['jadwal' => '⏱ Jadwal', 'manual' => '👆 Manual', 'peristiwa' => '⚡ Peristiwa', 'persetujuan' => '🙋 Persetujuan'];
    $warnaUsulan = [
        'disetujui'   => 'bg-green-100 text-green-800',
        'ditolak'     => 'bg-red-100 text-red-800',
        'gagal'       => 'bg-red-100 text-red-800',
        'kedaluwarsa' => 'bg-gray-100 text-gray-500',
        'batal'       => 'bg-gray-100 text-gray-500',
        'diproses'    => 'bg-amber-100 text-amber-800',
        'menunggu'    => 'bg-blue-100 text-blue-800',
    ];
    $labelUsulan = \App\Modules\KaryawanVirtual\Models\KaryawanVirtualUsulan::LABEL_STATUS;
@endphp
<div class="w-full px-6 py-4">
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

    {{-- Kiri: usulan + skill; kanan (layar lebar): jurnal kerja menempel saat digulir. --}}
    <div class="grid xl:grid-cols-[minmax(0,1fr)_420px] gap-6 items-start">
    <div class="min-w-0">

    {{-- Usulan menunggu persetujuan --}}
    @if ($usulanMenunggu->isNotEmpty())
        <h2 class="font-semibold text-slate-800 mb-2">Menunggu persetujuan ({{ $usulanMenunggu->count() }})</h2>
        <div class="bg-white rounded shadow divide-y divide-gray-100 mb-6 border-l-4 border-blue-400">
            @foreach ($usulanMenunggu as $u)
                <div class="p-3 flex flex-wrap items-start justify-between gap-3">
                    <div class="text-sm min-w-0">
                        <div class="font-semibold text-slate-800">{{ $u->judul }}</div>
                        @if ($u->alasan)<div class="text-xs text-slate-600">{{ $u->alasan }}</div>@endif
                        <div class="text-[11px] text-slate-400 mt-0.5">
                            Usulan #{{ $u->id }} · {{ $labelSkill[$u->skill] ?? $u->skill }} · diajukan {{ $u->created_at->format('d/m H:i') }}
                            · berlaku sampai {{ $u->kedaluwarsa_at->format('d/m H:i') }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('karyawan-virtual.usulan.putuskan', [$kode, $u->id]) }}" class="flex gap-2 shrink-0 ml-auto">
                        @csrf
                        <button type="submit" name="keputusan" value="tolak"
                                onclick="return confirm('Tolak usulan ini?')"
                                class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded text-xs">Tolak</button>
                        <button type="submit" name="keputusan" value="setuju"
                                onclick="return confirm('Setujui? Syaratnya diperiksa ulang dulu, lalu langsung dikerjakan.')"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-xs font-semibold">Setujui</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Skill --}}
    <h2 class="font-semibold text-slate-800 mb-2">Skill</h2>
    <div class="grid md:grid-cols-2 gap-4 mb-6">
        @foreach ($skills as $s)
            @php $skill = $s['skill']; $state = $s['state']; @endphp
            <div class="bg-white rounded shadow p-4 flex flex-col" x-data="{ rinci: false }">
                <div class="flex items-start justify-between gap-2 mb-1">
                    <div class="font-semibold text-slate-800">
                        {{ $skill->label() }}
                        @if ($s['percobaan'] ?? false)
                            <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800" title="Skill baru masih diuji; naik ke Otomatis setelah hasilnya dipercaya.">🧪 Dalam percobaan</span>
                        @endif
                    </div>
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
                            @if ($skill instanceof \App\Modules\KaryawanVirtual\Contracts\PunyaPengaturan)
                                <a href="{{ $skill->urlPengaturan($kode) }}" class="text-xs px-2 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">⚙ Pengaturan</a>
                            @endif
                        </form>
                    @elseif ($skill instanceof \App\Modules\KaryawanVirtual\Contracts\PunyaPengaturan)
                        <div class="mb-1"><a href="{{ $skill->urlPengaturan($kode) }}" class="text-xs px-2 py-1 rounded border border-indigo-300 text-indigo-700 hover:bg-indigo-50 font-semibold">⚙ Pengaturan</a></div>
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

    {{-- Riwayat usulan --}}
    @if ($usulanRiwayat->isNotEmpty())
        <h2 class="font-semibold text-slate-800 mb-2">Usulan terakhir diputus</h2>
        <div class="bg-white rounded shadow overflow-x-auto mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="text-left px-3 py-2">Diputus</th>
                        <th class="text-left px-3 py-2">Usulan</th>
                        <th class="text-left px-3 py-2">Status</th>
                        <th class="text-left px-3 py-2">Hasil</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($usulanRiwayat as $u)
                        <tr class="border-t border-gray-100 align-top">
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                {{ ($u->diputus_at ?? $u->updated_at)->format('d/m/Y H:i') }}
                                <div class="text-gray-400">{{ $u->pemutus?->name ?? 'Sistem' }}@if ($u->diputus_lewat && $u->diputus_lewat !== 'sistem') · {{ $u->diputus_lewat === 'telegram' ? 'Telegram' : 'web' }}@endif</div>
                            </td>
                            <td class="px-3 py-2">#{{ $u->id }} {{ $u->judul }}</td>
                            <td class="px-3 py-2"><span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $warnaUsulan[$u->status] ?? '' }}">{{ $labelUsulan[$u->status] ?? $u->status }}</span></td>
                            <td class="px-3 py-2 text-xs text-slate-600">
                                {{ $u->catatan_hasil }}
                                @if ($u->tautan)
                                    <a href="{{ $u->tautan }}" class="text-blue-600 underline">{{ $u->dokumen }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    </div>{{-- /kiri --}}

    {{-- Jurnal kerja --}}
    <aside class="min-w-0 xl:sticky xl:top-4 xl:max-h-[calc(100vh-2rem)] xl:flex xl:flex-col">
    <h2 class="font-semibold text-slate-800 mb-2">Jurnal Kerja</h2>
    <form method="GET" class="bg-white rounded shadow p-3 mb-3 grid grid-cols-2 gap-2 text-sm">
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Skill</label>
            <select name="skill" class="filter-auto border rounded px-2 py-1.5 w-full">
                <option value="">Semua skill</option>
                @foreach ($labelSkill as $key => $label)
                    <option value="{{ $key }}" @selected(request('skill') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tampilkan</label>
            <select name="tampil" class="filter-auto border rounded px-2 py-1.5 w-full">
                <option value="">Ada tindakan / keputusan</option>
                <option value="semua" @selected(request('tampil') === 'semua')>Semua (termasuk jalan manual tanpa tindakan)</option>
            </select>
        </div>
        @include('erp._partials.per-page-select')
    </form>

    <div class="bg-white rounded shadow divide-y divide-gray-100 xl:overflow-y-auto xl:min-h-0">
        @forelse ($jurnal as $j)
            @php
                // Catatan keputusan atas satu usulan: tampil sebagai hasil keputusannya, bukan "N diperiksa".
                $keputusan = null;
                if ($j->pemicu === 'persetujuan') {
                    $r0 = $j->rincian[0] ?? [];
                    $keputusan = $r0 + ['lencana' => match ($r0['status'] ?? null) {
                        'dibuat', 'diubah' => ['✅ Disetujui', 'bg-green-100 text-green-800'],
                        'ditolak'          => ['❌ Ditolak', 'bg-red-100 text-red-800'],
                        'gagal'            => ['⚠️ Gagal', 'bg-red-100 text-red-800'],
                        default            => ['➖ Tidak jadi', 'bg-gray-100 text-gray-600'],
                    }];
                }
                $usulanDisebut = collect($j->rincian)->pluck('usulan_id')->filter()->unique()
                    ->map(fn ($id) => $statusUsulan[$id] ?? null)->filter();
            @endphp
            <div x-data="{ buka: false }" class="text-sm">
                <div class="px-3 py-2 hover:bg-gray-50 cursor-pointer" @click="buka = !buka">
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="text-gray-500 whitespace-nowrap">
                            {{ $j->created_at->format('d/m/Y H:i') }} · {{ $labelPemicu[$j->pemicu] ?? $j->pemicu }}
                        </span>
                        @if ($keputusan)
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-semibold {{ $keputusan['lencana'][1] }}">{{ $keputusan['lencana'][0] }}</span>
                        @else
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-semibold {{ $warnaMode[$j->mode] ?? '' }}">{{ $labelMode[$j->mode] ?? $j->mode }}</span>
                        @endif
                    </div>
                    <div class="font-semibold text-slate-800 mt-0.5">{{ $labelSkill[$j->skill] ?? $j->skill }}</div>
                    <div class="text-slate-600">
                        @if ($keputusan)
                            {{ $keputusan['judul'] ?? $j->ringkasan }}
                            @if ($keputusan['tautan'] ?? null)
                                → <a href="{{ $keputusan['tautan'] }}" class="text-blue-600 underline font-semibold" @click.stop>{{ $keputusan['dokumen'] }}</a>
                            @elseif (!in_array($keputusan['status'] ?? null, ['dibuat', 'diubah', 'ditolak'], true) && ($keputusan['alasan'] ?? null))
                                <div class="text-xs text-slate-500">{{ $keputusan['alasan'] }}</div>
                            @endif
                        @else
                            {{ $j->ringkasan }}
                        @endif
                        <span class="text-xs text-blue-600 ml-1" x-text="buka ? '▲' : '▼'">▼</span>
                    </div>
                    @foreach ($usulanDisebut as $u)
                        @continue($keputusan)
                        <div class="text-xs mt-0.5">
                            Usulan #{{ $u->id }}:
                            <span class="rounded px-1.5 py-0.5 font-semibold {{ $warnaUsulan[$u->status] ?? '' }}">{{ $labelUsulan[$u->status] ?? $u->status }}</span>
                            @if ($u->pemutus)<span class="text-gray-400">{{ $u->pemutus->name }}{{ $u->diputus_at ? ', ' . $u->diputus_at->format('d/m H:i') : '' }}</span>@endif
                            @if ($u->tautan)
                                → <a href="{{ $u->tautan }}" class="text-blue-600 underline" @click.stop>{{ $u->dokumen }}</a>
                            @endif
                        </div>
                    @endforeach
                    @if ($j->keterangan_pemicu || ($j->user && !$keputusan))
                        <div class="text-[11px] text-gray-400">
                            {{ $j->keterangan_pemicu }}@if (!$keputusan && $j->keterangan_pemicu && $j->user) · @endif @if ($j->user && !$keputusan)oleh {{ $j->user->name }}@endif
                        </div>
                    @endif
                </div>
                <ul x-show="buka" x-cloak class="text-xs space-y-1 bg-gray-50 rounded mx-3 mb-2 p-2">
                    @foreach ($j->rincian as $r)
                        @php [$ikon, $label, $warna] = $status[$r['status']] ?? ['•', $r['status'], '']; @endphp
                        <li class="{{ $warna }}" title="{{ $label }}">
                            {{ $ikon }} <b>{{ $r['judul'] }}</b>
                            <span class="text-slate-500">— {{ $r['alasan'] }}</span>
                            @if ($r['tautan'] ?? null)
                                <a href="{{ $r['tautan'] }}" class="text-blue-600 underline">{{ $r['dokumen'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="px-3 py-6 text-center text-gray-400 text-sm">Belum ada jurnal kerja.</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $jurnal->links() }}</div>
    </aside>
    </div>{{-- /grid --}}
</div>
@endsection
