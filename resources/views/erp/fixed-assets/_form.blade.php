@php
    $isEdit = isset($asset);
    $a = $isEdit ? $asset : null;
@endphp

<div class="grid grid-cols-2 gap-4">
    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold mb-3">Informasi Aset</h2>

        <label class="block text-xs text-gray-500 mb-1">Kategori <span class="text-red-500">*</span></label>
        <select name="asset_category_id" class="w-full border rounded px-2 py-1.5 mb-3" required onchange="loadDefaults(this.value)">
            <option value="">— pilih —</option>
            @foreach($categories as $c)
                <option value="{{ $c->id }}"
                    data-life="{{ $c->default_useful_life_months }}"
                    data-salvage="{{ $c->default_salvage_value_percent }}"
                    data-depreciable="{{ $c->is_depreciable_default ? 1 : 0 }}"
                    @selected(old('asset_category_id', $a->asset_category_id ?? '') == $c->id)>
                    {{ $c->code }} — {{ $c->name }}
                </option>
            @endforeach
        </select>

        <label class="block text-xs text-gray-500 mb-1">Nama Aset <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $a->name ?? '') }}" class="w-full border rounded px-2 py-1.5 mb-3" required>

        <label class="block text-xs text-gray-500 mb-1">Deskripsi</label>
        <textarea name="description" rows="2" class="w-full border rounded px-2 py-1.5 mb-3">{{ old('description', $a->description ?? '') }}</textarea>

        <div class="grid grid-cols-2 gap-3 mb-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Serial Number</label>
                <input type="text" name="serial_number" value="{{ old('serial_number', $a->serial_number ?? '') }}" class="w-full border rounded px-2 py-1.5">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">PIC / Penanggung Jawab</label>
                <input type="text" name="responsible_person" value="{{ old('responsible_person', $a->responsible_person ?? '') }}" class="w-full border rounded px-2 py-1.5">
            </div>
        </div>

        <label class="block text-xs text-gray-500 mb-1">Gudang / Lokasi</label>
        <select name="warehouse_id" class="w-full border rounded px-2 py-1.5 mb-3">
            <option value="">— tanpa gudang —</option>
            @foreach($warehouses as $w)
                <option value="{{ $w->id }}" @selected(old('warehouse_id', $a->warehouse_id ?? '') == $w->id)>{{ $w->name }}</option>
            @endforeach
        </select>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tanggal Perolehan <span class="text-red-500">*</span></label>
                <input type="date" name="acquisition_date" id="acquisition_date" value="{{ old('acquisition_date', optional($a->acquisition_date ?? null)->format('Y-m-d') ?: now()->format('Y-m-d')) }}" class="w-full border rounded px-2 py-1.5" required oninput="syncStartFromAcq(); recalcDepreciation()">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Harga Perolehan <span class="text-red-500">*</span></label>
                <input type="text" inputmode="numeric" name="acquisition_cost" id="acquisition_cost" value="{{ old('acquisition_cost', $a->acquisition_cost ?? '') }}" class="w-full border rounded px-2 py-1.5 rupiah-input" required @if($isEdit && $a->source_type === 'purchase') readonly title="Biaya berasal dari faktur — tidak bisa diubah" @endif oninput="recalcDepreciation()">
            </div>
        </div>
    </div>

    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold mb-3">Penyusutan</h2>

        <label class="inline-flex items-center mb-3">
            <input type="hidden" name="is_depreciable" value="0">
            <input type="checkbox" name="is_depreciable" value="1" id="is_depreciable_chk"
                {{ old('is_depreciable', $a->is_depreciable ?? 1) ? 'checked' : '' }}
                onchange="toggleDepreciable()" class="mr-2">
            <span class="text-sm">Aset ini disusutkan (matikan untuk Tanah dll)</span>
        </label>

        <div id="depreciable_fields">
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Umur Ekonomis (bulan)</label>
                    <input type="number" min="1" name="useful_life_months" id="useful_life_months" value="{{ old('useful_life_months', $a->useful_life_months ?? '') }}" class="w-full border rounded px-2 py-1.5" oninput="recalcDepreciation()">
                    <p class="text-xs text-gray-400 mt-1">Berapa bulan aset disusutkan. Misal 60 = 5 tahun.</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Nilai Residu</label>
                    <input type="number" step="0.01" min="0" name="salvage_value" id="salvage_value" value="{{ old('salvage_value', $a->salvage_value ?? 0) }}" class="w-full border rounded px-2 py-1.5" oninput="recalcDepreciation()">
                    <p class="text-xs text-gray-400 mt-1">Estimasi nilai aset di akhir umur ekonomisnya yang <em>tidak</em> akan disusutkan (sisa nilai jual). Auto-terisi dari default kategori, boleh diubah.</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Mulai Disusutkan</label>
                    <input type="date" name="depreciation_start_date" id="depreciation_start_date" value="{{ old('depreciation_start_date', optional($a->depreciation_start_date ?? null)->format('Y-m-d')) }}" class="w-full border rounded px-2 py-1.5" oninput="markStartDirty(); recalcDepreciation()">
                    <p class="text-xs text-gray-400 mt-1">Default = tanggal perolehan. Bisa diganti untuk aset existing yang mulai disusutkan di tanggal berbeda.</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Selesai Disusutkan</label>
                    <input type="text" id="depreciation_end_display" class="w-full border rounded px-2 py-1.5 bg-gray-50" readonly placeholder="otomatis">
                    <p class="text-xs text-gray-400 mt-1">Auto = mulai disusutkan + umur ekonomis.</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Akumulasi Penyusutan Awal</label>
                    <input type="number" step="0.01" min="0" name="accumulated_depreciation" id="accumulated_depreciation" value="{{ old('accumulated_depreciation', $a->accumulated_depreciation ?? 0) }}" class="w-full border rounded px-2 py-1.5 bg-gray-50" readonly>
                    <p class="text-xs text-gray-400 mt-1">Auto dihitung dari (mulai disusutkan → hari ini) × tarif bulanan.</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Bulan Sudah Disusutkan</label>
                    <input type="number" min="0" name="months_depreciated" id="months_depreciated" value="{{ old('months_depreciated', $a->months_depreciated ?? 0) }}" class="w-full border rounded px-2 py-1.5 bg-gray-50" readonly>
                    <p class="text-xs text-gray-400 mt-1">Auto = jumlah bulan antara mulai disusutkan dan hari ini (max = umur ekonomis).</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mt-4">
    <label class="block text-xs text-gray-500 mb-1">Catatan</label>
    <textarea name="notes" rows="2" class="w-full border rounded px-2 py-1.5">{{ old('notes', $a->notes ?? '') }}</textarea>
</div>

@if($isEdit && $a->source_type === 'purchase' && $a->sourceInvoice)
    <div class="bg-blue-50 border border-blue-200 text-blue-700 px-3 py-2 rounded mt-3 text-sm">
        Aset ini berasal dari Faktur <a href="{{ route('purchasing.invoices.show', $a->source_invoice_id) }}" class="font-medium underline">{{ $a->sourceInvoice->invoice_number }}</a>.
        Biaya & akumulasi awal tidak bisa diubah dari sini.
    </div>
@endif

<div class="mt-4 flex gap-2">
    <button type="submit" name="_after_save" value="" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan Draf</button>
    <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm" onclick="return confirm('Simpan & POST aset?')">Simpan & Post</button>
    <a href="{{ route('fixed-assets.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
</div>

<script>
function loadDefaults(catId) {
    if (!catId) return;
    const opt = document.querySelector(`select[name="asset_category_id"] option[value="${catId}"]`);
    if (!opt) return;
    const lifeEl = document.getElementById('useful_life_months');
    const salvageEl = document.getElementById('salvage_value');
    const depEl = document.getElementById('is_depreciable_chk');
    const costEl = document.getElementById('acquisition_cost');

    if (lifeEl && !lifeEl.value) lifeEl.value = opt.dataset.life || '';
    if (depEl) depEl.checked = opt.dataset.depreciable === '1';
    toggleDepreciable();
    if (salvageEl && !salvageEl.value) {
        const cost = window.cleanNumber(costEl?.value || 0);
        const pct = parseFloat(opt.dataset.salvage || 0);
        if (cost > 0 && pct > 0) salvageEl.value = (cost * pct / 100).toFixed(2);
    }
    recalcDepreciation();
}
function toggleDepreciable() {
    const chk = document.getElementById('is_depreciable_chk');
    const fields = document.getElementById('depreciable_fields');
    if (!chk || !fields) return;
    fields.style.opacity = chk.checked ? '1' : '0.4';
    fields.querySelectorAll('input, select').forEach(el => {
        // jangan enable input yang memang readonly murni (display saja)
        if (el.id === 'depreciation_end_display' || el.id === 'accumulated_depreciation' || el.id === 'months_depreciated') {
            el.disabled = !chk.checked;
        } else {
            el.disabled = !chk.checked;
        }
    });
    if (chk.checked) recalcDepreciation();
}

// Track apakah user sudah mengubah tanggal mulai disusutkan secara manual.
// Default true di mode edit (anggap nilai dari DB sudah final), false di create.
let startDirty = {{ ($isEdit && ($a->depreciation_start_date ?? null)) ? 'true' : 'false' }};

function markStartDirty() { startDirty = true; }

// Sync mulai disusutkan = tgl perolehan, kecuali user sudah edit manual.
function syncStartFromAcq() {
    if (startDirty) return;
    const acq = document.getElementById('acquisition_date')?.value || '';
    setVal('depreciation_start_date', acq);
}

// Hitung jumlah bulan yang month-end-nya sudah lewat sejak start (bulan berjalan tidak dihitung).
// Contoh: start 2024-01-01, today 2026-05-07 → Jan 2024..Apr 2026 = 28 bulan.
function monthsBetween(startStr, todayStr) {
    if (!startStr || !todayStr) return 0;
    const s = new Date(startStr + 'T00:00:00');
    const t = new Date(todayStr + 'T00:00:00');
    if (isNaN(s.getTime()) || isNaN(t.getTime())) return 0;
    if (t < s) return 0;
    let months = (t.getFullYear() - s.getFullYear()) * 12 + (t.getMonth() - s.getMonth());
    return Math.max(0, months);
}

// End-of-month dari (start + n - 1) bulan. start dianggap sebagai "bulan ke-1".
function addMonthsEndOfMonth(startStr, n) {
    if (!startStr || !n) return '';
    const s = new Date(startStr + 'T00:00:00');
    if (isNaN(s.getTime()) || n <= 0) return '';
    const target = new Date(s.getFullYear(), s.getMonth() + (n - 1) + 1, 0);
    const yyyy = target.getFullYear();
    const mm = String(target.getMonth() + 1).padStart(2, '0');
    const dd = String(target.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function recalcDepreciation() {
    const chk = document.getElementById('is_depreciable_chk');
    if (!chk || !chk.checked) {
        setVal('months_depreciated', 0);
        setVal('accumulated_depreciation', 0);
        setVal('depreciation_end_display', '');
        return;
    }

    const start = document.getElementById('depreciation_start_date')?.value || '';
    const life = parseInt(document.getElementById('useful_life_months')?.value || '0', 10);
    const cost = window.cleanNumber(document.getElementById('acquisition_cost')?.value || '0');
    const salvage = parseFloat(document.getElementById('salvage_value')?.value || '0');

    // Selesai disusutkan = end of (start + life - 1) bulan
    const endStr = (start && life > 0) ? addMonthsEndOfMonth(start, life) : '';
    setVal('depreciation_end_display', endStr);

    // Bulan sudah disusutkan = bulan antara start dan today, capped di life
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
    let months = (start && life > 0) ? monthsBetween(start, todayStr) : 0;
    if (life > 0 && months > life) months = life;
    setVal('months_depreciated', months);

    // Accumulated = monthly × months, capped di (cost - salvage)
    const base = Math.max(0, cost - salvage);
    let accum = 0;
    if (life > 0 && months > 0 && base > 0) {
        const monthly = Math.round((base / life) * 100) / 100;
        accum = Math.round(monthly * months * 100) / 100;
        if (accum > base) accum = base;
    }
    setVal('accumulated_depreciation', accum.toFixed(2));
}

function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}

// Init: kalau create (start belum dirty) dan start kosong, isi dari acquisition.
syncStartFromAcq();
toggleDepreciable();
recalcDepreciation();
</script>
