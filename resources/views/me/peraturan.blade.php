@extends('layouts.karyawan')

@section('title', 'Peraturan Kerja — NOUD Karyawan')

@php
    $peraturan = config('peraturan_kerja');
@endphp

@section('header')
    <div class="flex items-center gap-2">
        <a href="{{ route('me.profil') }}" class="p-1 -ml-1 rounded-lg hover:bg-white/15">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-extrabold">Peraturan Kerja</h1>
    </div>
    <p class="text-white/70 text-xs mt-1">Ringkasan {{ $peraturan['sumber'] }}. Ketuk pasal untuk membaca.</p>
@endsection

@section('content')
    <div class="space-y-2 mt-2">
        @foreach ($peraturan['pasal'] as $pasal)
            <details class="group bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <summary class="flex items-center gap-3 p-4 cursor-pointer list-none select-none active:bg-slate-50">
                    <span class="w-9 h-9 shrink-0 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center text-sm font-extrabold">
                        {{ $pasal['no'] }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wide">Pasal {{ $pasal['no'] }}</p>
                        <p class="text-sm font-bold text-slate-800 leading-tight">{{ $pasal['judul'] }}</p>
                    </div>
                    <svg class="w-5 h-5 text-slate-300 shrink-0 transition group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </summary>

                <ol class="px-4 pb-4 pt-0 space-y-2 text-[13px] text-slate-700 leading-relaxed">
                    @foreach ($pasal['ayat'] as $i => $ayat)
                        @php $isArr = is_array($ayat); @endphp
                        <li class="flex gap-2">
                            @if (count($pasal['ayat']) > 1)
                                <span class="shrink-0 w-5 text-slate-400 font-semibold">{{ $i + 1 }}.</span>
                            @endif
                            <div class="min-w-0">
                                {{ $isArr ? $ayat['text'] : $ayat }}
                                @if ($isArr && ! empty($ayat['sub']))
                                    <ul class="mt-1.5 space-y-1">
                                        @foreach ($ayat['sub'] as $j => $sub)
                                            <li class="flex gap-2">
                                                <span class="shrink-0 text-slate-400">{{ ! empty($ayat['abjad']) ? chr(97 + $j) . '.' : '–' }}</span>
                                                <span>{{ $sub }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </details>
        @endforeach

        <p class="text-center text-[11px] text-slate-400 pt-3 px-4">
            Nominal gaji pokok, tunjangan makan, dan tanggal mulai kerja mengikuti Perjanjian Kerja masing-masing.
        </p>
    </div>
@endsection
