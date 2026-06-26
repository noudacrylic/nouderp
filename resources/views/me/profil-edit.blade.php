@extends('layouts.karyawan')

@section('title', 'Data Pribadi — NOUD Karyawan')

@php
    $fotoUrl = $karyawan->foto_path ? asset('storage/' . $karyawan->foto_path) : null;
    $ktpUrl  = $karyawan->ktp_path ? asset('storage/' . $karyawan->ktp_path) : null;
@endphp

@section('header')
    <div class="flex items-center gap-2">
        <a href="{{ route('me.profil') }}" class="p-1 -ml-1 rounded-lg hover:bg-white/15">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-extrabold">Data Pribadi</h1>
    </div>
    <p class="text-emerald-100/80 text-xs mt-1">Lengkapi atau perbarui data Anda di sini.</p>
@endsection

@section('content')
<form method="POST" action="{{ route('me.profil.update') }}" enctype="multipart/form-data"
      class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mt-2 space-y-4">
    @csrf

    {{-- No. HP (login) — read-only --}}
    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Nomor HP (untuk login)</label>
        <input type="text" value="{{ $karyawan->hp }}" disabled
               class="w-full border border-slate-200 bg-slate-50 text-slate-500 rounded-lg px-3 h-11 text-sm">
        <p class="text-[11px] text-slate-400 mt-1">Hubungi admin bila nomor HP perlu diubah.</p>
    </div>

    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email', $karyawan->email) }}"
               class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
    </div>

    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Alamat</label>
        <textarea name="alamat" rows="2" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">{{ old('alamat', $karyawan->alamat) }}</textarea>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">NIK (KTP)</label>
            <input type="text" name="nik" value="{{ old('nik', $karyawan->nik) }}" inputmode="numeric"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">NPWP <span class="text-slate-400 font-normal">(opsional)</span></label>
            <input type="text" name="npwp" value="{{ old('npwp', $karyawan->npwp) }}"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
            <p class="text-[11px] text-slate-400 mt-1">Boleh dikosongkan — kini NPWP pribadi = NIK.</p>
        </div>
    </div>

    <hr class="border-slate-100">
    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Rekening Bank (untuk gaji)</p>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Nama Bank</label>
            <input type="text" name="bank_name" value="{{ old('bank_name', $karyawan->bank_name) }}" placeholder="mis. BRI"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">No. Rekening</label>
            <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $karyawan->bank_account_number) }}" inputmode="numeric"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
        </div>
    </div>
    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Nama Pemilik Rekening</label>
        <input type="text" name="bank_account_holder" value="{{ old('bank_account_holder', $karyawan->bank_account_holder) }}"
               class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
    </div>

    <hr class="border-slate-100">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Foto Profil</label>
            @if ($fotoUrl)
                <img src="{{ $fotoUrl }}" alt="Foto" class="w-16 h-16 rounded-xl object-cover mb-1.5 border border-slate-200">
            @endif
            <input type="file" name="foto" accept="image/*" capture="user"
                   class="w-full text-xs text-slate-600 file:mr-2 file:py-2 file:px-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-xs file:font-semibold">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Foto KTP</label>
            @if ($ktpUrl)
                <img src="{{ $ktpUrl }}" alt="KTP" class="w-16 h-16 rounded-xl object-cover mb-1.5 border border-slate-200">
            @endif
            <input type="file" name="ktp" accept="image/*" capture="environment"
                   class="w-full text-xs text-slate-600 file:mr-2 file:py-2 file:px-2 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-xs file:font-semibold">
        </div>
    </div>

    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 rounded-xl">
        Simpan
    </button>
    <a href="{{ route('me.profil') }}" class="block text-center text-xs text-slate-400">Batal</a>
</form>
@endsection
