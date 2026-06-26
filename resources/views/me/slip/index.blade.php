@extends('layouts.karyawan')

@section('title', 'Slip Gaji — NOUD Karyawan')

@section('header')
    <div class="flex items-center gap-2">
        <a href="{{ route('me.profil') }}" class="p-1 -ml-1 rounded-lg hover:bg-white/15">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-extrabold">Slip Gaji</h1>
    </div>
    <p class="text-white/70 text-xs mt-1">Hanya periode yang sudah final.</p>
@endsection

@section('content')
    @if ($slips->isEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center mt-2">
            <p class="text-4xl mb-2">🧾</p>
            <p class="text-slate-500 text-sm">Belum ada slip gaji final.</p>
        </div>
    @else
        <div class="space-y-2.5 mt-1">
            @foreach ($slips as $s)
                <a href="{{ route('me.slip.show', $s->id) }}"
                   class="block bg-white rounded-2xl border border-slate-200 shadow-sm p-4 active:bg-slate-50">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <p class="font-bold text-slate-800 capitalize">{{ $s->periode->label ?? ($s->periode->bulan . '/' . $s->periode->tahun) }}</p>
                            <p class="text-[11px] text-slate-400">{{ $s->code }}</p>
                        </div>
                        <svg class="w-5 h-5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                    <div class="mt-3 pt-3 border-t border-slate-100">
                        <p class="text-[11px] text-slate-400 font-semibold uppercase tracking-wide">Diterima Bersih</p>
                        <p class="text-2xl font-extrabold text-emerald-700 mt-0.5">Rp {{ number_format((float) $s->total_gaji, 0, ',', '.') }}</p>
                    </div>
                    <span class="inline-block mt-2 text-[11px] text-blue-600 font-semibold">Lihat / Cetak slip resmi →</span>
                </a>
            @endforeach
        </div>
        <p class="text-center text-[11px] text-slate-400 mt-5">
            Rincian lengkap (tunjangan, lembur, potongan BPJS/PPh) ada di slip resmi.
        </p>
    @endif
@endsection
