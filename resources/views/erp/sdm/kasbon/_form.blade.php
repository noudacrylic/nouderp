@csrf
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Karyawan <span class="text-red-500">*</span></label>
        <select name="karyawan_id" class="w-full border rounded px-2 py-1.5 text-sm" required>
            <option value="">— Pilih karyawan —</option>
            @foreach($karyawans as $k)
                <option value="{{ $k->id }}" @selected(old('karyawan_id', $kasbon->karyawan_id ?? null) == $k->id)>
                    {{ $k->staf_code }} - {{ $k->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tanggal Pengajuan <span class="text-red-500">*</span></label>
        <input type="date" name="tanggal_pengajuan"
               value="{{ old('tanggal_pengajuan', optional($kasbon->tanggal_pengajuan ?? null)->format('Y-m-d') ?? now()->toDateString()) }}"
               class="w-full border rounded px-2 py-1.5 text-sm" required>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jumlah Pinjaman <span class="text-red-500">*</span></label>
        <input type="text" inputmode="numeric" name="jumlah_pinjaman"
               value="{{ old('jumlah_pinjaman', $kasbon->jumlah_pinjaman ?? '') }}"
               class="rupiah-input w-full border rounded px-2 py-1.5 text-sm text-right" placeholder="0" required>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cicilan per Bulan <span class="text-red-500">*</span></label>
        <input type="text" inputmode="numeric" name="cicilan_per_bulan"
               value="{{ old('cicilan_per_bulan', $kasbon->cicilan_per_bulan ?? '') }}"
               class="rupiah-input w-full border rounded px-2 py-1.5 text-sm text-right" placeholder="0" required>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tanggal Mulai Potong</label>
        <input type="date" name="tanggal_mulai_potong"
               value="{{ old('tanggal_mulai_potong', optional($kasbon->tanggal_mulai_potong ?? null)->format('Y-m-d')) }}"
               class="w-full border rounded px-2 py-1.5 text-sm">
        <p class="text-xs text-gray-400 mt-0.5">Opsional. Kalau kosong, mulai potong dari periode gaji berikutnya.</p>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Akun Pencairan (Kas/Bank)</label>
        <select name="cash_account_id" class="w-full border rounded px-2 py-1.5 text-sm">
            <option value="">— Pilih saat posting —</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected(old('cash_account_id', $kasbon->cash_account_id ?? null) == $a->id)>
                    {{ $a->code }} - {{ $a->name }}
                </option>
            @endforeach
        </select>
        <p class="text-xs text-gray-400 mt-0.5">Wajib diisi sebelum posting kasbon.</p>
    </div>
    <div class="md:col-span-2">
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="2" class="w-full border rounded px-2 py-1.5 text-sm">{{ old('notes', $kasbon->notes ?? '') }}</textarea>
    </div>
</div>
