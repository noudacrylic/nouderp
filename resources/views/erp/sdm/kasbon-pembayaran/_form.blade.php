@csrf
<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div class="md:col-span-2">
        <label class="block text-xs text-gray-500 mb-1">Kasbon <span class="text-red-500">*</span></label>
        <select name="kasbon_id" class="w-full border rounded px-2 py-1.5 text-sm" required>
            <option value="">— Pilih kasbon aktif —</option>
            @foreach($aktifKasbon as $k)
                <option value="{{ $k->id }}"
                        data-sisa="{{ $k->sisa_terhutang }}"
                        @selected(old('kasbon_id', $kp->kasbon_id ?? ($selectedKasbon->id ?? null)) == $k->id)>
                    {{ $k->code }} | {{ $k->karyawan->name }} | Sisa Rp {{ number_format($k->sisa_terhutang, 0, ',', '.') }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tanggal Bayar <span class="text-red-500">*</span></label>
        <input type="date" name="tanggal_bayar"
               value="{{ old('tanggal_bayar', optional($kp->tanggal_bayar ?? null)->format('Y-m-d') ?? now()->toDateString()) }}"
               class="w-full border rounded px-2 py-1.5 text-sm" required>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jumlah <span class="text-red-500">*</span></label>
        <input type="text" name="jumlah"
               value="{{ old('jumlah', $kp->jumlah ?? '') }}"
               class="w-full border rounded px-2 py-1.5 text-sm text-right" placeholder="0" required>
    </div>
    <div class="md:col-span-2">
        <label class="block text-xs text-gray-500 mb-1">Akun Kas/Bank Penerima <span class="text-red-500">*</span></label>
        <select name="cash_account_id" class="w-full border rounded px-2 py-1.5 text-sm" required>
            <option value="">— Pilih akun —</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected(old('cash_account_id', $kp->cash_account_id ?? null) == $a->id)>
                    {{ $a->code }} - {{ $a->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="md:col-span-2">
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="2" class="w-full border rounded px-2 py-1.5 text-sm">{{ old('notes', $kp->notes ?? '') }}</textarea>
    </div>
</div>
