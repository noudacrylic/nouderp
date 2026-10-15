{{-- Filter "Foto nota" di daftar dokumen. Param opsional: $auto (kirim form saat diganti). --}}
<div>
    <label class="block text-xs text-gray-500 mb-1">Foto nota</label>
    <select name="nota" class="{{ ($auto ?? false) ? 'filter-auto ' : '' }}border rounded px-2 py-1.5">
        <option value="">Semua</option>
        <option value="belum" @selected(request('nota') === 'belum')>Belum ada nota</option>
        <option value="ada" @selected(request('nota') === 'ada')>Sudah ada nota</option>
    </select>
</div>
