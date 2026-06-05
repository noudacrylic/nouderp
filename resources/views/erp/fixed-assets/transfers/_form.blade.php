@php $isEdit = isset($transfer); $tr = $transfer ?? null; $a = $asset ?? ($tr->asset ?? null); @endphp

<div class="bg-white rounded shadow p-4">
    @if(!$isEdit)
        <label class="block text-xs text-gray-500 mb-1">Aset <span class="text-red-500">*</span></label>
        @if($a)
            <input type="hidden" name="fixed_asset_id" value="{{ $a->id }}">
            <div class="border rounded px-2 py-1.5 mb-3 bg-gray-50 text-sm">{{ $a->asset_code }} — {{ $a->name }} (Gudang: {{ $a->warehouse->name ?? '—' }})</div>
        @else
            <select name="fixed_asset_id" id="asset_select" class="w-full border rounded px-2 py-1.5 mb-3" required>
                <option value="">— pilih aset (cari) —</option>
            </select>
            <div id="asset_info" class="text-xs text-gray-500 mb-3"></div>
        @endif
    @else
        <div class="bg-gray-50 border rounded px-3 py-2 mb-3 text-sm">
            <strong>{{ $tr->asset->asset_code ?? '-' }}</strong> — {{ $tr->asset->name ?? '-' }} · Gudang asal: {{ $tr->asset->warehouse->name ?? '-' }}
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 mb-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tanggal Transfer <span class="text-red-500">*</span></label>
            <input type="date" name="transfer_date" value="{{ old('transfer_date', optional($tr->transfer_date ?? null)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="w-full border rounded px-2 py-1.5" required>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Gudang Tujuan <span class="text-red-500">*</span></label>
            <select name="to_warehouse_id" class="w-full border rounded px-2 py-1.5" required>
                <option value="">— pilih —</option>
                @foreach($warehouses as $w)
                    <option value="{{ $w->id }}" @selected(old('to_warehouse_id', $tr->to_warehouse_id ?? '')==$w->id)>{{ $w->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Catatan / Alasan</label>
        <textarea name="notes" rows="2" class="w-full border rounded px-2 py-1.5">{{ old('notes', $tr->notes ?? '') }}</textarea>
    </div>
</div>

<div class="mt-4 flex gap-2">
    <button type="submit" name="_after_save" value="" class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan Draf</button>
    <button type="submit" name="_after_save" value="post" class="bg-green-600 text-white px-4 py-2 rounded text-sm" onclick="return confirm('Simpan & POST transfer?')">Simpan & Post</button>
    <a href="{{ route('fixed-assets.transfers.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
</div>

@if(!$isEdit && !($a ?? null))
<script>
const assetSel = document.getElementById('asset_select');
const info = document.getElementById('asset_info');
let assetCache = [];
async function loadAssets() {
    const res = await fetch('{{ route('fixed-assets.api.assets.active') }}');
    assetCache = await res.json();
    assetSel.innerHTML = '<option value="">— pilih aset —</option>' +
        assetCache.map(a => `<option value="${a.id}">${a.asset_code} — ${a.name} (${a.warehouse || 'tanpa gudang'})</option>`).join('');
}
assetSel.addEventListener('change', () => {
    const a = assetCache.find(x => x.id == assetSel.value);
    info.textContent = a ? `Gudang asal: ${a.warehouse || '—'}` : '';
});
loadAssets();
</script>
@endif
