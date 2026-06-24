@extends('layouts.karyawan')

@section('title', 'Absensi — NOUD Karyawan')

@php
    use App\Modules\SDM\Services\KaryawanHomeService;
    $fmtTime = fn ($t) => $t ? substr($t, 0, 5) : '–';
    $lastOut = fn ($r) => $r->off_work3 ?: ($r->off_work2 ?: $r->off_work1);
@endphp

@section('header')
    <h1 class="text-xl font-extrabold">Riwayat Absensi</h1>
    <div class="flex items-center justify-between mt-3 bg-white/10 rounded-xl px-2 py-1.5">
        <a href="{{ route('me.absensi', $prev) }}"
           class="p-1.5 rounded-lg hover:bg-white/15 text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="font-semibold capitalize">{{ $cursor->translatedFormat('F Y') }}</span>
        @if ($next)
            <a href="{{ route('me.absensi', $next) }}" class="p-1.5 rounded-lg hover:bg-white/15 text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        @else
            <span class="p-1.5 text-white/25">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        @endif
    </div>
@endsection

@section('content')
    @if ($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center mt-2">
            <p class="text-4xl mb-2">🗓️</p>
            <p class="text-slate-500 text-sm">Belum ada catatan absensi pada bulan ini.</p>
        </div>
    @else
        <div class="space-y-2.5 mt-1">
            @foreach ($rows as $r)
                @php [$label, $cls] = KaryawanHomeService::statusBadge($r->status); @endphp
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3.5 flex items-center gap-3">
                    {{-- Tanggal --}}
                    <div class="text-center w-12 shrink-0">
                        <p class="text-lg font-extrabold text-slate-800 leading-none">{{ $r->tanggal->format('d') }}</p>
                        <p class="text-[11px] text-slate-400 capitalize">{{ $r->tanggal->translatedFormat('D') }}</p>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="inline-block text-[11px] font-bold px-2 py-0.5 rounded-full {{ $cls }}">{{ $label }}</span>
                        @if (in_array($r->status, ['hadir','terlambat','setengah_hari','pulang_awal']))
                            <p class="text-xs text-slate-500 mt-1">
                                Masuk <span class="font-semibold text-slate-700">{{ $fmtTime($r->on_work1) }}</span>
                                · Pulang <span class="font-semibold text-slate-700">{{ $fmtTime($lastOut($r)) }}</span>
                            </p>
                        @elseif ($r->remark)
                            <p class="text-xs text-slate-400 mt-1 truncate">{{ $r->remark }}</p>
                        @endif
                    </div>
                    {{-- Indikator telat/jam --}}
                    <div class="text-right shrink-0">
                        @if ($r->late_minutes > 0)
                            <p class="text-[11px] font-bold text-amber-600">+{{ $r->late_minutes }}m</p>
                        @endif
                        @if ($r->working_hours > 0)
                            <p class="text-[11px] text-slate-400">{{ rtrim(rtrim(number_format($r->working_hours, 1, ',', '.'), '0'), ',') }} jam</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-center text-[11px] text-slate-400 mt-5">
            Bersumber dari mesin fingerprint. Ada keliru? Ajukan koreksi via admin / menu Izin.
        </p>
    @endif
@endsection
