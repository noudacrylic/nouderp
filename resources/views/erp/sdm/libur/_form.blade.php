@php $libur = $libur ?? null; @endphp


<div class="bg-white rounded shadow p-5 max-w-2xl">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tanggal <span class="text-red-500">*</span></label>
            <input type="date" name="tanggal" value="{{ old('tanggal', optional($libur->tanggal ?? null)->format('Y-m-d')) }}" required class="border rounded px-3 py-2 w-full">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Cuti Bersama?</label>
            <select name="is_cuti_bersama" class="border rounded px-3 py-2 w-full">
                <option value="0" @selected(! old('is_cuti_bersama', $libur->is_cuti_bersama ?? false))>Tidak (Hari Libur Reguler)</option>
                <option value="1" @selected(old('is_cuti_bersama', $libur->is_cuti_bersama ?? false))>Ya (Cuti Bersama)</option>
            </select>
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Nama <span class="text-red-500">*</span></label>
            <input type="text" name="nama" value="{{ old('nama', $libur->nama ?? '') }}" required class="border rounded px-3 py-2 w-full" placeholder="contoh: Hari Raya Idul Fitri">
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="catatan" rows="2" class="border rounded px-3 py-2 w-full">{{ old('catatan', $libur->catatan ?? '') }}</textarea>
        </div>
    </div>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        <a href="{{ route('sdm.libur.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</div>
