@extends('layouts.karyawan')

@section('title', 'Profil — NOUD Karyawan')

@php
    $fotoUrl = $karyawan->foto_path ? asset('storage/' . $karyawan->foto_path) : null;
    $inisial = strtoupper(mb_substr($karyawan->name, 0, 1));
@endphp

@section('header')
    <div class="flex items-start justify-between">
        <h1 class="text-xl font-extrabold">Profil</h1>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" title="Keluar"
                    class="bg-white/15 hover:bg-white/25 text-white rounded-full p-2 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </button>
        </form>
    </div>

    {{-- Kartu identitas --}}
    <div class="flex items-center gap-3 mt-4">
        @if ($fotoUrl)
            <img src="{{ $fotoUrl }}" alt="Foto" class="w-16 h-16 rounded-2xl object-cover border-2 border-white/40 shadow">
        @else
            <div class="w-16 h-16 rounded-2xl bg-white/20 border-2 border-white/40 flex items-center justify-center text-2xl font-extrabold">
                {{ $inisial }}
            </div>
        @endif
        <div class="min-w-0">
            <p class="text-lg font-extrabold leading-tight truncate">{{ $karyawan->name }}</p>
            <p class="text-emerald-100/90 text-xs">{{ $karyawan->jabatan ?: 'Karyawan' }}</p>
            <p class="text-emerald-200/70 text-[11px] font-mono mt-0.5">{{ $karyawan->staf_code }}</p>
        </div>
    </div>
@endsection

@section('content')
    <div class="space-y-3 mt-1">

        {{-- Pengingat data belum lengkap --}}
        @if (count($missing) > 0)
            <a href="{{ route('me.profil.edit') }}"
               class="block bg-amber-50 border border-amber-200 rounded-2xl p-3.5 shadow-sm">
                <div class="flex items-start gap-2.5">
                    <span class="text-lg">📝</span>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-amber-800">Lengkapi data pribadi</p>
                        <p class="text-[11px] text-amber-700 mt-0.5">Belum lengkap: {{ implode(', ', $missing) }}.</p>
                        <p class="text-[11px] text-amber-600 font-semibold mt-1">Ketuk untuk melengkapi →</p>
                    </div>
                </div>
            </a>
        @endif

        {{-- Menu Data Pribadi --}}
        @include('me.partials._profil_link', [
            'href'  => route('me.profil.edit'),
            'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
            'title' => 'Data Pribadi',
            'desc'  => 'Email, alamat, NIK, NPWP, rekening, foto',
            'tone'  => 'emerald',
        ])

        {{-- Menu Cuti & SP --}}
        @include('me.partials._profil_link', [
            'href'  => route('me.cuti'),
            'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
            'title' => 'Cuti & SP',
            'desc'  => 'Sisa cuti tahunan & status peringatan',
            'tone'  => 'indigo',
        ])

        {{-- Menu Slip Gaji --}}
        @include('me.partials._profil_link', [
            'href'  => route('me.slip'),
            'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'title' => 'Slip Gaji',
            'desc'  => 'Rincian gaji periode yang sudah final',
            'tone'  => 'green',
        ])

        {{-- Toggle notifikasi Web Push --}}
        @include('me.partials._push_toggle')

        <p class="text-center text-[11px] text-slate-400 pt-3">
            NOUD Karyawan · {{ $karyawan->department->name ?? 'Noud Acrylic' }}
        </p>
    </div>
@endsection
