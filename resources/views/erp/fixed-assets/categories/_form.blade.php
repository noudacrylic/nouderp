@php $c = $category ?? null; $isEdit = isset($category); @endphp

<style>
.acc-pick { position: relative; }
.acc-pick-dropdown { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #d1d5db; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 30; max-height: 240px; overflow-y: auto; display: none; }
.acc-pick-dropdown.show { display: block; }
.acc-pick-dropdown .item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
.acc-pick-dropdown .item:hover { background: #eff6ff; }
.acc-pick-dropdown .item .code { font-family: monospace; font-size: 11px; color: #2563eb; font-weight: bold; }
.acc-pick-dropdown .item .name { color: #1f2937; }
.acc-pick-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #9ca3af; font-size: 18px; }
.acc-pick-clear:hover { color: #ef4444; }
</style>

<div class="bg-white rounded shadow p-4">
    <div class="grid grid-cols-2 gap-4 mb-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Nama Kategori <span class="text-red-500">*</span></label>
            <input type="text" name="name" value="{{ old('name', $c->name ?? '') }}" class="w-full border rounded px-2 py-1.5" required placeholder="contoh: Mesin & Peralatan">
            @if($isEdit)
                <p class="text-xs text-gray-400 mt-1">Kode kategori: <code class="bg-gray-100 px-1 rounded">{{ $c->code }}</code> (otomatis, tidak bisa diubah).</p>
            @else
                <p class="text-xs text-gray-400 mt-1">Kode kategori akan otomatis dibuat dari nama (mis. "Mesin & Peralatan" → kode <code>MES</code>, kode aset jadi <code>FA/MES/2026/0001</code>).</p>
            @endif
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <label class="inline-flex items-center mt-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $c->is_active ?? 1) ? 'checked' : '' }} class="mr-2">
                <span class="text-sm">Aktif</span>
            </label>
        </div>
    </div>

    <div class="mb-3">
        <label class="block text-xs text-gray-500 mb-1">Deskripsi</label>
        <textarea name="description" rows="2" class="w-full border rounded px-2 py-1.5">{{ old('description', $c->description ?? '') }}</textarea>
    </div>

    <h3 class="font-semibold text-sm mb-2 mt-4">Default Penyusutan</h3>
    <p class="text-xs text-gray-500 mb-3">Saat user membuat aset baru dan memilih kategori ini, nilai-nilai berikut akan otomatis terisi di form aset (dan tetap bisa diubah per-aset). Jadi cukup di-set sekali di sini agar tidak perlu input ulang setiap kali.</p>
    <div class="grid grid-cols-3 gap-3 mb-4">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Umur Default (bulan)</label>
            <input type="number" min="1" name="default_useful_life_months" value="{{ old('default_useful_life_months', $c->default_useful_life_months ?? 60) }}" class="w-full border rounded px-2 py-1.5">
            <p class="text-xs text-gray-400 mt-1">Misal 60 = 5 tahun penyusutan.</p>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Nilai Residu Default (%)</label>
            <input type="number" step="0.01" min="0" max="100" name="default_salvage_value_percent" value="{{ old('default_salvage_value_percent', $c->default_salvage_value_percent ?? 10) }}" class="w-full border rounded px-2 py-1.5">
            <p class="text-xs text-gray-400 mt-1">Estimasi % nilai aset di akhir umur ekonomis yang <em>tidak</em> disusutkan (sisa nilai jual). Misal 10% dari Rp 10jt → residu Rp 1jt.</p>
        </div>
        <div class="flex items-end">
            <label class="inline-flex items-center mb-2">
                <input type="hidden" name="is_depreciable_default" value="0">
                <input type="checkbox" name="is_depreciable_default" value="1" {{ old('is_depreciable_default', $c->is_depreciable_default ?? 1) ? 'checked' : '' }} class="mr-2">
                <span class="text-sm">Default disusutkan</span>
            </label>
        </div>
    </div>

    <h3 class="font-semibold text-sm mb-2 mt-4">Mapping Akun</h3>
    <p class="text-xs text-gray-500 mb-3">Akun-akun yang akan otomatis dipakai saat aset di kategori ini bergerak (perolehan & penyusutan). Akun keuntungan/kerugian disposisi tidak per kategori — di-set sekali di <a href="{{ route('fixed-assets.settings.edit') }}" class="text-blue-600 underline">Pengaturan Aset Tetap</a> (sistem auto pilih akun gain atau loss berdasarkan hasil perhitungan).</p>

    <div class="grid grid-cols-2 gap-4 mb-3">
        @include('erp.fixed-assets.categories._account_picker', [
            'name' => 'fixed_asset_account_id',
            'label' => 'Akun Aset Tetap',
            'required' => true,
            'value' => old('fixed_asset_account_id', $c->fixed_asset_account_id ?? ''),
            'displayValue' => $c?->fixedAssetAccount ? ($c->fixedAssetAccount->code . ' — ' . $c->fixedAssetAccount->name) : '',
            'searchTypes' => 'asset',
            'helper' => 'Saldo normal Debit. Misal: 1230 Mesin & Peralatan. Di-Debit saat aset diperoleh.',
        ])

        @include('erp.fixed-assets.categories._account_picker', [
            'name' => 'accumulated_depreciation_account_id',
            'label' => 'Akun Akumulasi Penyusutan',
            'required' => false,
            'value' => old('accumulated_depreciation_account_id', $c->accumulated_depreciation_account_id ?? ''),
            'displayValue' => $c?->accumulatedDepreciationAccount ? ($c->accumulatedDepreciationAccount->code . ' — ' . $c->accumulatedDepreciationAccount->name) : '',
            'searchTypes' => 'asset',
            'helper' => 'Contra-asset (saldo normal Credit). Misal: 1238 Akum Penyusutan Mesin. Boleh kosong untuk Tanah.',
        ])

        @include('erp.fixed-assets.categories._account_picker', [
            'name' => 'depreciation_expense_account_id',
            'label' => 'Akun Beban Penyusutan',
            'required' => false,
            'value' => old('depreciation_expense_account_id', $c->depreciation_expense_account_id ?? ''),
            'displayValue' => $c?->depreciationExpenseAccount ? ($c->depreciationExpenseAccount->code . ' — ' . $c->depreciationExpenseAccount->name) : '',
            'searchTypes' => 'expense',
            'helper' => 'Misal: 6230 Beban Depresiasi Mesin. Di-Debit setiap close periode bulanan. Boleh kosong untuk Tanah.',
        ])
    </div>
</div>

<div class="mt-4 flex gap-2">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan</button>
    <a href="{{ route('fixed-assets.categories.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
</div>

<script>
(function () {
    const SEARCH_URL = "{{ route('accounts.search') }}";

    document.querySelectorAll('.acc-pick').forEach(function (wrap) {
        const input = wrap.querySelector('.acc-pick-input');
        const hidden = wrap.querySelector('.acc-pick-id');
        const dropdown = wrap.querySelector('.acc-pick-dropdown');
        const clearBtn = wrap.querySelector('.acc-pick-clear');
        const types = (wrap.dataset.types || '').split(',').map(s => s.trim()).filter(Boolean);
        let timer;

        async function doSearch(q) {
            const params = new URLSearchParams();
            if (q) params.append('q', q);
            types.forEach(t => params.append('types[]', t));
            try {
                const res = await fetch(`${SEARCH_URL}?${params.toString()}`);
                const list = await res.json();
                renderResults(list);
            } catch (e) { console.error(e); }
        }

        function renderResults(list) {
            dropdown.innerHTML = '';
            if (!list.length) {
                dropdown.innerHTML = '<div class="item text-gray-400">Tidak ada akun cocok.</div>';
            } else {
                list.forEach(a => {
                    const div = document.createElement('div');
                    div.className = 'item';
                    div.innerHTML = `<div class="code">${a.code}</div><div class="name">${a.name}</div>`;
                    div.addEventListener('click', () => {
                        hidden.value = a.id;
                        input.value = `${a.code} — ${a.name}`;
                        dropdown.classList.remove('show');
                    });
                    dropdown.appendChild(div);
                });
            }
            dropdown.classList.add('show');
        }

        input.addEventListener('input', () => {
            hidden.value = '';
            clearTimeout(timer);
            timer = setTimeout(() => doSearch(input.value.trim()), 200);
        });
        input.addEventListener('focus', () => {
            doSearch(input.value.trim());
        });
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) dropdown.classList.remove('show');
        });
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                hidden.value = '';
                input.value = '';
                input.focus();
            });
        }
    });
})();
</script>
