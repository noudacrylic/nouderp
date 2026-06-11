@php $sp = $sp ?? null; @endphp


<div class="bg-white rounded shadow p-5 max-w-3xl">
    <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Karyawan <span class="text-red-500">*</span></label>
            <select name="karyawan_id" required class="border rounded px-3 py-2 w-full">
                <option value="">— pilih karyawan —</option>
                @foreach($karyawans as $k)
                    <option value="{{ $k->id }}" @selected(old('karyawan_id', $sp->karyawan_id ?? '') == $k->id)>
                        {{ $k->name }} ({{ $k->staf_code }})
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Level Sanksi <span class="text-red-500">*</span></label>
            <select name="sanksi" required class="border rounded px-3 py-2 w-full">
                @foreach(['SP1','SP2','SP3'] as $s)
                    <option value="{{ $s }}" @selected(old('sanksi', $sp->sanksi ?? 'SP1') === $s)>{{ $s }}</option>
                @endforeach
            </select>
            <div class="text-[11px] text-gray-400 mt-1">SP3 = masa percobaan 1 bulan, bisa diberhentikan (Pasal 8).</div>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tanggal Terbit <span class="text-red-500">*</span></label>
            <input type="date" name="tanggal_terbit" value="{{ old('tanggal_terbit', optional($sp->tanggal_terbit ?? now())->format('Y-m-d')) }}" required class="border rounded px-3 py-2 w-full">
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Berlaku Sampai</label>
            <input type="date" name="berlaku_sampai" value="{{ old('berlaku_sampai', optional($sp->berlaku_sampai ?? null)->format('Y-m-d')) }}" class="border rounded px-3 py-2 w-full">
            <div class="text-[11px] text-gray-400 mt-1">Pasal 8: SP berlaku 6 bulan. Kosongkan kalau tidak ada batas waktu.</div>
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Alasan <span class="text-red-500">*</span></label>
            <textarea name="alasan" required rows="3" class="border rounded px-3 py-2 w-full">{{ old('alasan', $sp->alasan ?? '') }}</textarea>
        </div>
        <div class="col-span-2">
            <label class="block text-xs text-gray-500 mb-1">Catatan</label>
            <textarea name="catatan" rows="2" class="border rounded px-3 py-2 w-full">{{ old('catatan', $sp->catatan ?? '') }}</textarea>
        </div>
    </div>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        <a href="{{ route('sdm.sp.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</div>
