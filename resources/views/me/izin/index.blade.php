@extends('layouts.karyawan')

@section('title', 'Izin — NOUD Karyawan')

@section('header')
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-extrabold">Pengajuan Izin</h1>
        <a href="{{ route('me.izin.create') }}"
           class="bg-white/15 hover:bg-white/25 text-white text-sm font-semibold px-3 py-1.5 rounded-lg inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Ajukan
        </a>
    </div>
    <p class="text-white/70 text-xs mt-1">Cuti, sakit, izin, tukar hari, toleransi, dll.</p>
@endsection

@section('content')
    @if ($requests->isEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center mt-2">
            <p class="text-4xl mb-2">📝</p>
            <p class="text-slate-500 text-sm">Belum ada pengajuan izin.</p>
            <a href="{{ route('me.izin.create') }}" class="inline-block mt-3 bg-emerald-600 text-white text-sm font-semibold px-4 py-2 rounded-lg">Ajukan Izin Pertama</a>
        </div>
    @else
        <div class="space-y-2.5 mt-1">
            @foreach ($requests as $r)
                @php [$label, $cls] = $r->statusBadge(); @endphp
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-slate-800 text-sm">{{ $r->typeLabel() }}</span>
                        <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $cls }}">{{ $label }}</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $r->tanggal->translatedFormat('d M Y') }}
                        @if ($r->tanggal_akhir)
                            <span class="text-slate-400">s/d</span> {{ $r->tanggal_akhir->translatedFormat('d M Y') }}
                        @endif
                    </p>
                    @if ($r->alasan)
                        <p class="text-xs text-slate-600 mt-1.5">{{ $r->alasan }}</p>
                    @endif
                    @if ($r->isRejected() && $r->review_notes)
                        <p class="text-[11px] text-red-600 mt-1.5">Alasan ditolak: {{ $r->review_notes }}</p>
                    @endif
                    @if ($r->lampiran_path)
                        <a href="{{ \Illuminate\Support\Facades\Storage::url($r->lampiran_path) }}" target="_blank"
                           class="text-[11px] text-blue-600 underline mt-1.5 inline-block">Lihat lampiran</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection
