@extends('layouts.cs')

@section('title', 'Profil — NOUD Chat')

@php
    $u = auth()->user();
    $inisial = mb_strtoupper(mb_substr($u->name ?: $u->username, 0, 1));
    $isIosHintPerlu = true;
@endphp

@section('topbar')
    <div class="px-4 py-3.5">
        <h1 class="text-lg font-extrabold">Profil</h1>
    </div>
@endsection

@section('content')
<div class="p-4 space-y-4">

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-3.5">
        <span class="w-12 h-12 shrink-0 rounded-xl bg-teal-100 text-teal-700 text-lg font-extrabold flex items-center justify-center">
            {{ $inisial }}
        </span>
        <div class="min-w-0">
            <p class="text-sm font-bold text-slate-800 truncate">{{ $u->name }}</p>
            <p class="text-xs text-slate-500 font-mono truncate">{{ $u->username }}</p>
        </div>
    </div>

    {{-- Langganan push: endpoint ERP, bukan endpoint /me. Langganan menempel ke
         pengguna, jadi CS yang sudah menyalakan notifikasi di ERP desktop tetap
         perlu menyalakannya sekali lagi DI HP — itu perangkat yang berbeda. --}}
    @include('me.partials._push_toggle', [
        'pushUrls' => [
            'subscribe'   => route('push.subscribe'),
            'unsubscribe' => route('push.unsubscribe'),
            'test'        => route('push.test'),
        ],
        'pushAksen'      => 'teal',
        'pushKeterangan' => 'Dapatkan pemberitahuan saat pelanggan mengirim chat.',
    ])

    {{-- Pintu ke ERP hanya untuk yang memang punya. Akun CS chat-saja tidak
         perlu melihat tautan yang ujungnya cuma halaman ditolak. --}}
    @if (! $u->pwa_crm || $u->menuPermissions()->exists() || in_array($u->role, ['super_admin', 'admin'], true))
        <a href="{{ url('/erp') }}"
           class="block bg-white rounded-2xl border border-slate-200 shadow-sm p-4 active:bg-slate-50">
            <p class="text-sm font-bold text-slate-800">Buka ERP</p>
            <p class="text-[11px] text-slate-400 mt-0.5">Versi lengkap untuk layar besar.</p>
        </a>
    @endif

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit"
                class="w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-4 text-sm font-bold text-red-600 active:bg-red-50">
            Keluar
        </button>
    </form>

    <p class="text-center text-[11px] text-slate-400 pt-2">NOUD Chat · Noud Acrylic</p>
</div>
@endsection
