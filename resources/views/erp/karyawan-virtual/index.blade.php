@extends('layouts.erp')

@section('content')
@php
    $warnaMode = [
        'nonaktif'    => 'bg-gray-100 text-gray-500',
        'bayangan'    => 'bg-amber-100 text-amber-800',
        'persetujuan' => 'bg-blue-100 text-blue-800',
        'otomatis'    => 'bg-green-100 text-green-800',
    ];
    $labelMode = \App\Modules\KaryawanVirtual\Services\KaryawanVirtualService::LABEL_MODE;
@endphp
<div class="w-full px-6 py-4 max-w-6xl">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-slate-800">Karyawan Virtual</h1>
        <p class="text-xs text-slate-500 mt-0.5 max-w-3xl">
            Otomasi ERP dalam bentuk karyawan: tiap karyawan virtual punya skill, dan setiap pekerjaannya
            tercatat di jurnal kerja — termasuk yang sengaja dilewati beserta alasannya.
        </p>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($karyawan as $k)
            <a href="{{ route('karyawan-virtual.show', $k['kode']) }}"
               class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 hover:border-blue-300 hover:shadow transition block">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-3xl">{{ $k['ikon'] }}</span>
                    <div>
                        <div class="font-semibold text-slate-800">{{ $k['nama'] }}</div>
                        <div class="text-xs text-slate-500">Divisi {{ $k['divisi'] }} · 🤖 virtual</div>
                    </div>
                </div>
                <p class="text-xs text-slate-600 mb-3">{{ $k['deskripsi'] }}</p>
                @if ($k['usulan_menunggu'] > 0)
                    <div class="mb-3 rounded bg-blue-50 text-blue-800 text-xs font-semibold px-2 py-1">
                        🙋 {{ $k['usulan_menunggu'] }} usulan menunggu persetujuan
                    </div>
                @endif
                <div class="space-y-1 mb-3">
                    @foreach ($k['daftar_skill'] as $s)
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="text-slate-700 truncate">{{ $s['label'] }}</span>
                            <span class="shrink-0 rounded px-1.5 py-0.5 font-semibold {{ $warnaMode[$s['mode']] ?? '' }}">{{ $labelMode[$s['mode']] ?? $s['mode'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="text-[11px] text-slate-400 border-t border-slate-100 pt-2">
                    @if ($k['jurnal_terbaru'])
                        Terakhir: {{ $k['jurnal_terbaru']->created_at->diffForHumans() }} — {{ $k['jurnal_terbaru']->ringkasan }}
                    @else
                        Belum ada jurnal kerja.
                    @endif
                </div>
            </a>
        @endforeach
    </div>
</div>
@endsection
