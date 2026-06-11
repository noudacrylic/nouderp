@extends('layouts.erp')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-lg font-semibold mb-6">Tambah Izin / Jadwal Lembur</h1>


    <form method="POST" action="{{ route('sdm.izin.store') }}" class="bg-white rounded shadow p-5"
          x-data="{ type: '{{ old('type', 'cuti') }}' }">
        @csrf
        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Karyawan <span class="text-red-500">*</span></label>
                <select name="karyawan_id" required class="border rounded px-3 py-2 w-full">
                    <option value="">— pilih —</option>
                    @foreach($karyawans as $k)
                        <option value="{{ $k->id }}" @selected(old('karyawan_id', $selected) == $k->id)>
                            {{ $k->name }} ({{ $k->staf_code }}) — {{ $k->department->name ?? '-' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
                <input type="date" name="tanggal" required value="{{ old('tanggal', now()->toDateString()) }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tipe <span class="text-red-500">*</span></label>
                <select name="type" x-model="type" required class="border rounded px-3 py-2 w-full">
                    @foreach($types as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Conditional: Sesi tanggal utama (only tukar_setengah_hari) --}}
            <div x-show="type === 'tukar_setengah_hari'" x-cloak>
                <label class="block text-xs text-gray-500 mb-1">Sesi Tanggal Ini <span class="text-red-500">*</span></label>
                <select name="sesi" class="border rounded px-3 py-2 w-full">
                    <option value="">— pilih —</option>
                    <option value="pagi" @selected(old('sesi') === 'pagi')>Pagi (½ awal)</option>
                    <option value="sore" @selected(old('sesi') === 'sore')>Sore (½ akhir)</option>
                </select>
            </div>

            {{-- Conditional: Tanggal Pasangan + Sesi-nya (tukar_hari + tukar_setengah_hari) --}}
            <div x-show="type === 'tukar_hari' || type === 'tukar_setengah_hari'" x-cloak class="col-span-2 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Tanggal Pasangan <span class="text-red-500">*</span></label>
                    <input type="date" name="paired_date" value="{{ old('paired_date') }}" class="border rounded px-3 py-2 w-full">
                    <div class="text-[11px] text-gray-400 mt-1">Tanggal lain yang dipakai sebagai pasangan tukar.</div>
                </div>
                <div x-show="type === 'tukar_setengah_hari'" x-cloak>
                    <label class="block text-xs text-gray-500 mb-1">Sesi Tanggal Pasangan <span class="text-red-500">*</span></label>
                    <select name="paired_sesi" class="border rounded px-3 py-2 w-full">
                        <option value="">— pilih —</option>
                        <option value="pagi" @selected(old('paired_sesi') === 'pagi')>Pagi (½ awal)</option>
                        <option value="sore" @selected(old('paired_sesi') === 'sore')>Sore (½ akhir)</option>
                    </select>
                </div>
            </div>

            {{-- Conditional: Penyesuaian Jam --}}
            <div x-show="type === 'penyesuaian_jam'" x-cloak>
                <label class="block text-xs text-gray-500 mb-1">Jam Masuk Custom</label>
                <input type="time" name="jam_masuk_override" value="{{ old('jam_masuk_override') }}" class="border rounded px-3 py-2 w-full">
                <div class="text-[11px] text-gray-400 mt-1">Kosongkan jika tidak diganti.</div>
            </div>
            <div x-show="type === 'penyesuaian_jam'" x-cloak>
                <label class="block text-xs text-gray-500 mb-1">Jam Pulang Custom</label>
                <input type="time" name="jam_pulang_override" value="{{ old('jam_pulang_override') }}" class="border rounded px-3 py-2 w-full">
                <div class="text-[11px] text-gray-400 mt-1">Kosongkan jika tidak diganti.</div>
            </div>

            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Catatan</label>
                <textarea name="notes" rows="2" class="border rounded px-3 py-2 w-full">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="mt-5 bg-gray-50 border rounded p-3 text-xs text-gray-600">
            <strong>Efek per tipe:</strong>
            <ul class="list-disc pl-5 mt-1 space-y-0.5">
                <li><strong>Cuti</strong> — semua batch skip, dapat gaji pokok + tunjangan harian (full pay)</li>
                <li><strong>Sakit</strong> — semua batch skip, dapat gaji pokok tanpa tunjangan harian</li>
                <li><strong>Izin Pagi</strong> — batch 08:30 skip → datang siang → status setengah hari</li>
                <li><strong>Izin Sore</strong> — batch 16:30 skip → pulang siang → status setengah hari</li>
                <li><strong>Tukar Hari</strong> — tanggal ini + tanggal pasangan = keduanya hadir + tunjangan (full pay)</li>
                <li><strong>Tukar Setengah Hari</strong> — kerja ½ hari di tanggal ini & ½ hari di tanggal pasangan. Keduanya hadir + tunjangan</li>
                <li><strong>Penyesuaian Jam</strong> — jam masuk/pulang ditimpa khusus hari ini (mis. tidak istirahat, pulang jam 3)</li>
                <li><strong>Jadwal Lembur</strong> — batch lembur jalan → lembur dihitung</li>
            </ul>
        </div>

        <div class="mt-5 flex gap-2">
            <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
            <a href="{{ route('sdm.izin.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
        </div>
    </form>
</div>

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak] { display: none !important; }</style>
@endpush
@endsection
