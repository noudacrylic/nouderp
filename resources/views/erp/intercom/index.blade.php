@extends('layouts.erp')

@php
    $statusLabel = [
        'tersambung'   => ['Tersambung',   'text-emerald-700 bg-emerald-50'],
        'tak_terjawab' => ['Tak terjawab', 'text-amber-700 bg-amber-50'],
        'ditolak'      => ['Ditolak',      'text-slate-600 bg-slate-100'],
        'sibuk'        => ['Sibuk',        'text-slate-600 bg-slate-100'],
        'offline'      => ['Offline',      'text-slate-500 bg-slate-100'],
        'gagal'        => ['Gagal',        'text-red-700 bg-red-50'],
    ];
    $nama = fn (string $k) => ($stasiun[$k]['ikon'] ?? '') . ' ' . ($stasiun[$k]['label'] ?? $k);
@endphp

@section('content')
<div class="w-full px-6 py-4 max-w-5xl">

    <div class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">Intercom Divisi</h1>
        <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
            Klik divisi untuk langsung bicara &mdash; panggilan tersambung tanpa diangkat, didahului nada
            &ldquo;ding-dong&rdquo;. Biarkan halaman ini terbuka di perangkat stasiun (tablet/HP jangan dikunci).
            Suara mengalir langsung antar perangkat di Wi-Fi kantor dan tidak direkam.
        </p>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl p-5 mb-6" data-ic-isi="besar"></div>

    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
        <div class="px-4 py-2.5 border-b border-slate-100 text-sm font-semibold text-slate-700">Riwayat panggilan (2 hari terakhir)</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">Waktu</th>
                    <th class="text-left px-4 py-2">Dari</th>
                    <th class="text-left px-4 py-2">Ke</th>
                    <th class="text-left px-4 py-2">Status</th>
                    <th class="text-right px-4 py-2">Durasi</th>
                    <th class="text-left px-4 py-2">Akun</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($riwayat as $r)
                    @php [$lbl, $kls] = $statusLabel[$r->status] ?? [$r->status, 'text-slate-600 bg-slate-100']; @endphp
                    <tr class="border-t border-slate-100">
                        <td class="px-4 py-2 text-slate-600 whitespace-nowrap">{{ $r->mulai_at->format('d/m H:i') }}</td>
                        <td class="px-4 py-2">{{ $nama($r->dari) }}</td>
                        <td class="px-4 py-2">{{ $nama($r->ke) }}</td>
                        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-md text-xs font-semibold {{ $kls }}">{{ $lbl }}</span></td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ $r->durasi_detik ? sprintf('%02d:%02d', intdiv($r->durasi_detik, 60), $r->durasi_detik % 60) : '—' }}
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $r->user->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">Belum ada panggilan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
