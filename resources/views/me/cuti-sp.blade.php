@extends('layouts.karyawan')

@section('title', 'Cuti & SP — NOUD Karyawan')

@php
    // Warna badge per level SP (graduasi: makin berat makin merah).
    $spTone = fn ($s) => match ($s) {
        'SP1'   => 'bg-amber-100 text-amber-700 border-amber-300',
        'SP2'   => 'bg-orange-100 text-orange-700 border-orange-300',
        'SP3'   => 'bg-red-100 text-red-700 border-red-300',
        default => 'bg-slate-100 text-slate-600 border-slate-300',
    };
    $pct = $hakCuti > 0 ? min(100, round($cutiTerpakai / $hakCuti * 100)) : 0;
@endphp

@section('header')
    <div class="flex items-center gap-2">
        <a href="{{ route('me.profil') }}" class="p-1 -ml-1 rounded-lg hover:bg-white/15">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-extrabold">Cuti &amp; SP</h1>
    </div>
    <p class="text-white/70 text-xs mt-1">Hak cuti &amp; status kedisiplinan tahun {{ $year }}.</p>
@endsection

@section('content')
    {{-- ───────── Kartu sisa cuti ───────── --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mt-2">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-[11px] text-slate-400 font-semibold uppercase tracking-wide">Sisa Cuti {{ $year }}</p>
                <p class="text-4xl font-extrabold text-emerald-700 leading-tight mt-1">
                    {{ $sisaCuti }}<span class="text-base font-bold text-slate-400"> / {{ $hakCuti }} hari</span>
                </p>
            </div>
            <div class="text-5xl">🌴</div>
        </div>
        <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
            <div class="h-full bg-emerald-500 rounded-full" style="width: {{ $pct }}%"></div>
        </div>
        <p class="text-xs text-slate-500 mt-2">{{ $cutiTerpakai }} hari terpakai tahun ini.</p>
    </div>

    {{-- ───────── Riwayat tanggal cuti ───────── --}}
    @if ($cutiRows->isNotEmpty())
        <div class="mt-4">
            <p class="text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-2 px-1">Riwayat Cuti {{ $year }}</p>
            <div class="space-y-2">
                @foreach ($cutiRows as $c)
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm px-3.5 py-2.5 flex items-center gap-3">
                        <div class="text-center w-12 shrink-0">
                            <p class="text-lg font-extrabold text-slate-800 leading-none">{{ $c->tanggal->format('d') }}</p>
                            <p class="text-[11px] text-slate-400 capitalize">{{ $c->tanggal->translatedFormat('M') }}</p>
                        </div>
                        <div class="flex-1 min-w-0">
                            <span class="inline-block text-[11px] font-bold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700">Cuti</span>
                            @if ($c->remark)
                                <p class="text-xs text-slate-400 mt-1 truncate">{{ $c->remark }}</p>
                            @endif
                        </div>
                        <p class="text-[11px] text-slate-400 capitalize shrink-0">{{ $c->tanggal->translatedFormat('l') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ───────── Surat Peringatan ───────── --}}
    <div class="mt-5">
        <p class="text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-2 px-1">Surat Peringatan</p>

        @if ($spAktif->isEmpty() && $spRiwayat->isEmpty())
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 text-center">
                <p class="text-4xl mb-2">✅</p>
                <p class="text-slate-500 text-sm">Tidak ada Surat Peringatan. Pertahankan!</p>
            </div>
        @else
            @foreach ($spAktif as $sp)
                <div class="bg-white rounded-2xl border-2 {{ $spTone($sp->sanksi) }} shadow-sm p-4 mb-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-extrabold px-2.5 py-0.5 rounded-full border {{ $spTone($sp->sanksi) }}">
                            {{ $sp->sanksi }} · Aktif
                        </span>
                        <span class="text-[11px] text-slate-400 shrink-0">Terbit {{ $sp->tanggal_terbit->translatedFormat('d M Y') }}</span>
                    </div>
                    @if ($sp->alasan)
                        <p class="text-sm text-slate-700 mt-2">{{ $sp->alasan }}</p>
                    @endif
                    <p class="text-[11px] text-slate-500 mt-2">
                        Berlaku sampai
                        {{ $sp->berlaku_sampai ? $sp->berlaku_sampai->translatedFormat('d M Y') : 'tanpa batas waktu' }}.
                    </p>
                </div>
            @endforeach

            @if ($spRiwayat->isNotEmpty())
                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-2 mt-4 px-1">Riwayat (tidak aktif)</p>
                @foreach ($spRiwayat as $sp)
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm px-3.5 py-2.5 mb-2 opacity-75">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{{ $sp->sanksi }}</span>
                            <span class="text-[11px] text-slate-400 shrink-0">{{ $sp->tanggal_terbit->translatedFormat('d M Y') }}</span>
                        </div>
                        @if ($sp->alasan)
                            <p class="text-xs text-slate-500 mt-1.5">{{ $sp->alasan }}</p>
                        @endif
                    </div>
                @endforeach
            @endif
        @endif
    </div>

    <p class="text-center text-[11px] text-slate-400 mt-5">
        Data read-only. Pertanyaan soal cuti / SP hubungi admin SDM.
    </p>
@endsection
