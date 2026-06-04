@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Kehadiran — {{ $att->karyawan->name }} <span class="text-gray-400 text-sm">{{ $att->tanggal->translatedFormat('D, d/m/Y') }}</span></h1>
    <a href="{{ route('sdm.attendance.index', $att->periode_id) }}" class="text-gray-600 px-3 py-1.5 text-sm">Batal</a>
</div>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
        <ul class="list-disc pl-5">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('sdm.attendance.update', $att->id) }}" class="bg-white rounded shadow p-5 max-w-3xl">
    @csrf @method('PUT')

    <div class="grid grid-cols-3 gap-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">On Work 1 (Datang)</label>
            <input type="time" name="on_work1" value="{{ old('on_work1', $att->on_work1 ? substr($att->on_work1, 0, 5) : '') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Off Work 1 (Pulang)</label>
            <input type="time" name="off_work1" value="{{ old('off_work1', $att->off_work1 ? substr($att->off_work1, 0, 5) : '') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <div></div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">On Work 2 (Mulai Lembur)</label>
            <input type="time" name="on_work2" value="{{ old('on_work2', $att->on_work2 ? substr($att->on_work2, 0, 5) : '') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Off Work 2 (Pulang Lembur)</label>
            <input type="time" name="off_work2" value="{{ old('off_work2', $att->off_work2 ? substr($att->off_work2, 0, 5) : '') }}" class="border rounded px-3 py-2 w-full">
            <div class="text-[11px] text-gray-400 mt-1">17:30=1jam · 19:00=2jam · 20:00=3jam</div>
        </div>
        <div></div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">On Work 3</label>
            <input type="time" name="on_work3" value="{{ old('on_work3', $att->on_work3 ? substr($att->on_work3, 0, 5) : '') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Off Work 3</label>
            <input type="time" name="off_work3" value="{{ old('off_work3', $att->off_work3 ? substr($att->off_work3, 0, 5) : '') }}" class="border rounded px-3 py-2 w-full">
        </div>
        <div></div>

        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Status (override)</label>
            <select name="status" class="border rounded px-3 py-2 w-full">
                <option value="">— hitung otomatis dari jam —</option>
                @foreach(['hadir','terlambat','setengah_hari','pulang_awal','tidak_hadir','libur','cuti','sakit'] as $s)
                    <option value="{{ $s }}" @selected(old('status', $att->edited_manually ? $att->status : '') === $s)>{{ ucwords(str_replace('_',' ',$s)) }}</option>
                @endforeach
            </select>
            <div class="text-[11px] text-gray-400 mt-1">Status auto saat ini: <strong>{{ ucwords(str_replace('_',' ', $att->status)) }}</strong></div>
        </div>
        <div></div>

        <div class="col-span-3">
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="remark" rows="2" class="border rounded px-3 py-2 w-full">{{ old('remark', $att->remark) }}</textarea>
        </div>
    </div>

    <div class="mt-5 flex gap-2">
        <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan & Hitung Ulang Slip</button>
        <a href="{{ route('sdm.attendance.index', $att->periode_id) }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</form>
@endsection
