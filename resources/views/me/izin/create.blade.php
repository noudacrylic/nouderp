@extends('layouts.karyawan')

@section('title', 'Ajukan Izin — NOUD Karyawan')

@section('header')
    <div class="flex items-center gap-2">
        <a href="{{ route('me.izin') }}" class="p-1 -ml-1 rounded-lg hover:bg-white/15">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-extrabold">Ajukan Izin</h1>
    </div>
@endsection

@section('content')
<form method="POST" action="{{ route('me.izin.store') }}" enctype="multipart/form-data"
      x-data="{ type: '{{ old('type', 'cuti') }}' }"
      class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mt-2 space-y-4">
    @csrf

    {{-- Tipe --}}
    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Jenis Izin</label>
        <select name="type" x-model="type" class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm bg-white">
            @foreach ($types as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Tanggal (+ tanggal akhir utk cuti/sakit) --}}
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Tanggal</label>
            <input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm" required>
        </div>
        <div x-show="type === 'cuti' || type === 'sakit'" x-cloak>
            <label class="block text-xs font-semibold text-slate-600 mb-1">s/d (opsional)</label>
            <input type="date" name="tanggal_akhir" value="{{ old('tanggal_akhir') }}"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
        </div>
    </div>

    {{-- Tukar hari --}}
    <div x-show="type === 'tukar_hari' || type === 'tukar_setengah_hari'" x-cloak>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Tanggal Pasangan (pengganti)</label>
        <input type="date" name="paired_date" value="{{ old('paired_date') }}"
               class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
    </div>
    <div x-show="type === 'tukar_setengah_hari'" x-cloak class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Sesi</label>
            <select name="sesi" class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm bg-white">
                <option value="">—</option>
                <option value="pagi" @selected(old('sesi')==='pagi')>Pagi</option>
                <option value="sore" @selected(old('sesi')==='sore')>Sore</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Sesi Pasangan</label>
            <select name="paired_sesi" class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm bg-white">
                <option value="">—</option>
                <option value="pagi" @selected(old('paired_sesi')==='pagi')>Pagi</option>
                <option value="sore" @selected(old('paired_sesi')==='sore')>Sore</option>
            </select>
        </div>
    </div>

    {{-- Penyesuaian jam --}}
    <div x-show="type === 'penyesuaian_jam'" x-cloak class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Jam Masuk</label>
            <input type="time" name="jam_masuk_override" value="{{ old('jam_masuk_override') }}"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Jam Pulang</label>
            <input type="time" name="jam_pulang_override" value="{{ old('jam_pulang_override') }}"
                   class="w-full border border-slate-300 rounded-lg px-3 h-11 text-sm">
        </div>
    </div>

    {{-- Info toleransi --}}
    <div x-show="type === 'toleransi'" x-cloak class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-[11px] text-amber-800">
        ⚠️ Toleransi untuk keadaan darurat (mis. banjir) pada hari yang <b>sudah ada scan</b> tapi terlambat.
        Wajib lampirkan <b>foto bukti</b>. Perlu verifikasi approver.
    </div>

    {{-- Alasan --}}
    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Alasan</label>
        <textarea name="alasan" rows="3" required
                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                  placeholder="Jelaskan alasan izin…">{{ old('alasan') }}</textarea>
    </div>

    {{-- Lampiran --}}
    <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">
            Lampiran Foto
            <span x-show="type === 'toleransi'" x-cloak class="text-red-500">(wajib)</span>
            <span x-show="type !== 'toleransi'" x-cloak class="text-slate-400 font-normal">(opsional)</span>
        </label>
        <input type="file" name="lampiran" accept="image/*" capture="environment"
               class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-sm file:font-semibold">
    </div>

    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-3 rounded-xl">
        Kirim Pengajuan
    </button>
</form>
@endsection
