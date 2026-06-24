@extends('layouts.karyawan')

@section('title', 'Beranda — NOUD Karyawan')

@php
    $toneRing = [
        'green' => 'border-green-200 bg-green-50',
        'amber' => 'border-amber-200 bg-amber-50',
        'red'   => 'border-red-200 bg-red-50',
        'blue'  => 'border-blue-200 bg-blue-50',
        'slate' => 'border-slate-200 bg-white',
    ][$todayMessage['tone']] ?? 'border-slate-200 bg-white';
@endphp

@section('header')
    <div class="flex items-start justify-between">
        <div>
            <p class="text-emerald-100 text-sm">{{ $greeting }},</p>
            <h1 class="text-2xl font-extrabold leading-tight">{{ $panggilan }}</h1>
            <p class="text-emerald-200/80 text-xs mt-0.5">{{ $karyawan->jabatan ?: 'Karyawan' }}</p>
        </div>
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

    {{-- Tanggal + jam live --}}
    <div class="flex items-end justify-between mt-4">
        <p class="text-emerald-100/90 text-xs capitalize">{{ $now->translatedFormat('l, d F Y') }}</p>
        <div id="me-clock" class="text-2xl font-bold tabular-nums tracking-tight leading-none">{{ $now->format('H:i:s') }}</div>
    </div>

    {{-- Kondisi saat ini (disesuaikan jadwal kerja) --}}
    <div id="me-condition" class="mt-3 inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-semibold text-white transition-colors"
         style="background-color: rgba(255,255,255,.15)">
        <span id="me-cond-icon">🕗</span>
        <span id="me-cond-label">—</span>
        <span id="me-cond-sub" class="font-normal text-white/75 text-xs"></span>
    </div>
@endsection

@section('content')
    {{-- Kartu status hari ini --}}
    <div class="rounded-2xl border {{ $toneRing }} shadow-sm p-4 flex items-center gap-4">
        <div class="text-4xl leading-none">{{ $todayMessage['icon'] }}</div>
        <div class="min-w-0">
            <p class="font-bold text-slate-800">{{ $todayMessage['title'] }}</p>
            <p class="text-sm text-slate-500">{{ $todayMessage['message'] }}</p>
        </div>
    </div>

    {{-- Metrik bulan ini --}}
    <div class="mt-5">
        <h2 class="text-sm font-bold text-slate-600 mb-2 px-1">Kehadiran bulan ini</h2>
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 text-center">
                <p class="text-2xl font-extrabold text-emerald-700">{{ $metrics['hari_hadir'] }}</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Hari Hadir</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 text-center">
                <p class="text-2xl font-extrabold text-green-600">{{ rtrim(rtrim(number_format($metrics['jam_kerja'], 1, ',', '.'), '0'), ',') }}</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Jam Kerja</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 text-center">
                <p class="text-2xl font-extrabold text-indigo-600">{{ rtrim(rtrim(number_format($metrics['jam_lembur'], 1, ',', '.'), '0'), ',') }}</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Jam Lembur</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 text-center">
                <p class="text-2xl font-extrabold text-emerald-600">{{ $metrics['tepat_waktu'] }}</p>
                <p class="text-[11px] text-slate-500 mt-0.5">Tepat Waktu</p>
            </div>
        </div>

        @if ($metrics['terlambat'] || $metrics['tidak_hadir'])
            <div class="grid grid-cols-2 gap-3 mt-3">
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 text-center">
                    <p class="text-xl font-bold text-amber-600">{{ $metrics['terlambat'] }}</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Terlambat</p>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 text-center">
                    <p class="text-xl font-bold text-red-500">{{ $metrics['tidak_hadir'] }}</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Tidak Hadir</p>
                </div>
            </div>
        @endif
    </div>

    {{-- Izin aktif/akan datang & SP berlaku --}}
    @if ($izinAktif->isNotEmpty() || $spAktif->isNotEmpty())
        <div class="mt-5 space-y-2.5">
            @foreach ($spAktif as $sp)
                <a href="{{ route('me.cuti') }}"
                   class="block rounded-2xl border-2 border-red-200 bg-red-50 shadow-sm p-3.5 active:bg-red-100">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-red-700 text-sm flex items-center gap-1.5">
                            <span>⚠️</span> Surat Peringatan {{ $sp->sanksi }}
                        </span>
                        <span class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">Aktif</span>
                    </div>
                    <p class="text-xs text-red-600/90 mt-1">
                        Berlaku sampai
                        {{ $sp->berlaku_sampai ? $sp->berlaku_sampai->translatedFormat('d M Y') : 'tanpa batas waktu' }}.
                    </p>
                </a>
            @endforeach

            @foreach ($izinAktif as $izin)
                @php [$label, $cls] = $izin->statusBadge(); @endphp
                <a href="{{ route('me.izin') }}"
                   class="block rounded-2xl border border-slate-200 bg-white shadow-sm p-3.5 active:bg-slate-50">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-slate-800 text-sm flex items-center gap-1.5">
                            <span>📝</span> {{ $izin->typeLabel() }}
                        </span>
                        <span class="text-[11px] font-bold px-2 py-0.5 rounded-full {{ $cls }}">{{ $label }}</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $izin->tanggal->translatedFormat('d M Y') }}
                        @if ($izin->tanggal_akhir)
                            <span class="text-slate-400">s/d</span> {{ $izin->tanggal_akhir->translatedFormat('d M Y') }}
                        @elseif ($izin->paired_date)
                            <span class="text-slate-400">↔</span> {{ $izin->paired_date->translatedFormat('d M Y') }}
                        @endif
                    </p>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Pintasan --}}
    <div class="mt-5">
        <a href="{{ route('me.absensi') }}"
           class="flex items-center justify-between bg-white rounded-2xl border border-slate-200 shadow-sm p-4 active:bg-slate-50">
            <span class="flex items-center gap-3">
                <span class="bg-emerald-100 text-emerald-700 rounded-xl p-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </span>
                <span class="font-semibold text-slate-700">Riwayat Absensi</span>
            </span>
            <svg class="w-5 h-5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

    <p class="text-center text-[11px] text-slate-400 mt-6">
        Data absensi bersumber dari mesin fingerprint kantor.
    </p>
@endsection

@push('scripts')
<script>
(function () {
    const SCHED   = @json($schedule);
    const clockEl = document.getElementById('me-clock');
    const pill    = document.getElementById('me-condition');
    const iconEl  = document.getElementById('me-cond-icon');
    const labelEl = document.getElementById('me-cond-label');
    const subEl   = document.getElementById('me-cond-sub');

    const pad   = n => String(n).padStart(2, '0');
    const toMin = s => { if (!s) return null; const p = s.split(':'); return (+p[0]) * 60 + (+p[1]); };

    // Tentukan kondisi berdasarkan menit-ke-dalam-hari (t) vs jadwal hari ini.
    function condition(t) {
        if (!SCHED || !SCHED.has) {
            return { icon: '🗓️', label: 'Tidak terjadwal', sub: '', bg: 'rgba(255,255,255,.15)' };
        }
        if (SCHED.is_off) {
            return { icon: '🌴', label: 'Hari Libur', sub: 'Selamat beristirahat', bg: 'rgba(56,189,248,.30)' };
        }

        const masuk = toMin(SCHED.masuk),  pulang = toMin(SCHED.pulang);
        const istS  = toMin(SCHED.ist_start), istE = toMin(SCHED.ist_end);
        const lIn   = toMin(SCHED.lembur_in), lOut = toMin(SCHED.lembur_out);
        const liS   = toMin(SCHED.ist_lembur_start), liE = toMin(SCHED.ist_lembur_end);

        const WORK  = 'rgba(34,197,94,.32)';   // hijau — jam kerja
        const REST  = 'rgba(251,191,36,.34)';  // amber — istirahat
        const OT     = 'rgba(129,140,248,.36)'; // indigo — lembur
        const IDLE  = 'rgba(255,255,255,.15)';

        // Sebelum jam masuk
        if (masuk !== null && t < masuk) {
            return { icon: '🕗', label: 'Belum Mulai', sub: 'masuk ' + SCHED.masuk, bg: IDLE };
        }
        // Istirahat (di dalam jam kerja)
        if (istS !== null && istE !== null && t >= istS && t < istE) {
            return { icon: '☕', label: 'Istirahat', sub: 'sampai ' + SCHED.ist_end, bg: REST };
        }
        // Jam kerja normal
        if (masuk !== null && pulang !== null && t >= masuk && t < pulang) {
            return { icon: '💪', label: 'Jam Kerja', sub: 'sampai ' + SCHED.pulang, bg: WORK };
        }
        // Setelah jam pulang
        if (pulang !== null && t >= pulang) {
            if (SCHED.has_lembur && lIn !== null && lOut !== null) {
                if (t < lIn) {
                    return { icon: '🌇', label: 'Selesai Kerja', sub: 'lembur ' + SCHED.lembur_in, bg: IDLE };
                }
                if (t >= lIn && t < lOut) {
                    if (liS !== null && liE !== null && t >= liS && t < liE) {
                        return { icon: '☕', label: 'Istirahat Lembur', sub: 'sampai ' + SCHED.ist_lembur_end, bg: REST };
                    }
                    return { icon: '🌙', label: 'Lembur', sub: 'sampai ' + SCHED.lembur_out, bg: OT };
                }
            }
            return { icon: '✅', label: 'Di Luar Jam Kerja', sub: 'sampai jumpa besok', bg: IDLE };
        }
        return { icon: '🕗', label: '—', sub: '', bg: IDLE };
    }

    function tick() {
        const now = new Date();
        clockEl.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        const t = now.getHours() * 60 + now.getMinutes() + now.getSeconds() / 60;
        const c = condition(t);
        iconEl.textContent  = c.icon;
        labelEl.textContent = c.label;
        subEl.textContent   = c.sub ? ('· ' + c.sub) : '';
        pill.style.backgroundColor = c.bg;
    }

    tick();
    setInterval(tick, 1000);
})();
</script>
@endpush
