@php $isEdit = isset($disposal); $d = $disposal ?? null; $a = $asset ?? ($d->asset ?? null); @endphp

<div class="bg-white rounded shadow p-4">
    @if(!$isEdit)
        <label class="block text-xs text-gray-500 mb-1">Aset <span class="text-red-500">*</span></label>
        @if($a)
            <input type="hidden" name="fixed_asset_id" value="{{ $a->id }}">
            <div class="border rounded px-2 py-1.5 mb-3 bg-gray-50 text-sm">
                <strong>{{ $a->asset_code }}</strong> — {{ $a->name }}
                · Cost: {{ number_format($a->acquisition_cost, 0, ',', '.') }}
                · Akumulasi: {{ number_format($a->accumulated_depreciation, 0, ',', '.') }}
                · <strong>Nilai Buku: {{ number_format($a->current_book_value, 0, ',', '.') }}</strong>
            </div>
        @else
            <select name="fixed_asset_id" id="asset_select" class="w-full border rounded px-2 py-1.5 mb-3" required>
                <option value="">— pilih aset (cari) —</option>
            </select>
            <div id="asset_info" class="text-xs text-gray-500 mb-3"></div>
        @endif
    @else
        <div class="bg-gray-50 border rounded px-3 py-2 mb-3 text-sm">
            <strong>{{ $d->asset->asset_code ?? '-' }}</strong> — {{ $d->asset->name ?? '-' }}
            · Nilai Buku saat draft: {{ number_format($d->book_value_at_disposal, 2, ',', '.') }}
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tanggal Disposisi <span class="text-red-500">*</span></label>
            <input type="date" name="disposal_date" value="{{ old('disposal_date', optional($d->disposal_date ?? null)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="w-full border rounded px-2 py-1.5" required>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tipe Disposisi <span class="text-red-500">*</span></label>
            <select name="disposal_type" class="w-full border rounded px-2 py-1.5" required onchange="toggleProceeds()" id="disposal_type">
                @foreach(['sale'=>'Jual','damage'=>'Rusak','loss'=>'Hilang','donation'=>'Donasi','scrap'=>'Scrap'] as $v=>$l)
                    <option value="{{ $v }}" @selected(old('disposal_type', $d->disposal_type ?? 'sale')==$v)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Nilai Jual / Penerimaan</label>
            <input type="number" step="0.01" min="0" name="proceeds_amount" value="{{ old('proceeds_amount', $d->proceeds_amount ?? 0) }}" class="w-full border rounded px-2 py-1.5" id="proceeds_amount">
            <p class="text-xs text-gray-400 mt-1">Untuk Jual: nilai jual. Untuk Rusak/Hilang biasanya 0 (atau klaim asuransi).</p>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Akun Penerimaan (Kas/Bank/AR)</label>
            <select name="proceeds_account_id" class="w-full border rounded px-2 py-1.5" id="proceeds_account">
                <option value="">— default Kas —</option>
                @foreach($proceedsAccounts as $acc)
                    <option value="{{ $acc->id }}" @selected(old('proceeds_account_id', $d->proceeds_account_id ?? '')==$acc->id)>{{ $acc->code }} — {{ $acc->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Catatan</label>
        <textarea name="notes" rows="2" class="w-full border rounded px-2 py-1.5">{{ old('notes', $d->notes ?? '') }}</textarea>
    </div>
</div>

<div class="mt-4 flex gap-2">
    <button type="submit" name="_after_save" value="" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan Draft</button>
    <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm" onclick="return confirm('Simpan & POST disposisi? Aset akan ter-disposed & jurnal terbuat.')">Simpan & Post</button>
    <a href="{{ route('fixed-assets.disposals.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
</div>

<script>
function toggleProceeds() {
    const t = document.getElementById('disposal_type').value;
    const p = document.getElementById('proceeds_amount');
    if (t !== 'sale' && parseFloat(p.value || 0) === 0) {
        p.placeholder = '0 (opsional untuk non-sale)';
    }
}
toggleProceeds();
</script>

@if(!$isEdit && !($a ?? null))
<script>
const assetSel = document.getElementById('asset_select');
const info = document.getElementById('asset_info');
let assetCache = [];
let assetTS = null;

async function loadAssets() {
    const res = await fetch('{{ route('fixed-assets.api.assets.active') }}');
    assetCache = await res.json();

    assetSel.innerHTML = '<option value="">— pilih aset —</option>' +
        assetCache.map(a => `<option value="${a.id}">${a.asset_code} — ${a.name}</option>`).join('');

    // Live search via Tom Select (sudah ter-load di layout erp).
    if (assetTS) { assetTS.destroy(); }
    assetTS = new TomSelect(assetSel, {
        placeholder: 'Ketik kode atau nama aset…',
        allowEmptyOption: true,
        searchField: ['text'],
        maxOptions: 500,
    });

    assetTS.on('change', (value) => {
        const a = assetCache.find(x => x.id == value);
        info.innerHTML = a
            ? `Cost: ${a.acquisition_cost.toLocaleString()} · Akumulasi: ${a.accumulated_depreciation.toLocaleString()} · <strong>Nilai Buku: ${a.book_value.toLocaleString()}</strong>`
            : '';
    });
}
loadAssets();
</script>
@endif
