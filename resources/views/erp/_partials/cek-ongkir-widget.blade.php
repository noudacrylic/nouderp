{{--
    Widget cek ongkir + alamat MANDIRI (dipakai di Garansi; SO/Invoice pakai shipping-embed sendiri).
    Param:
      $custId    — id customer (tetap)
      $whId      — id gudang asal (tetap)
      $targetSel — CSS selector input yang diisi nominal ongkir terpilih (mis. "input[name=shipping_cost]")
    Hasil pilih kurir → isi nominal ongkir ke $targetSel (+ dispatch 'input' agar Alpine x-model ikut).
--}}
@php
    $kaOn = \App\Models\ShippingSetting::for('kiriminaja')->is_enabled;
    $btOn = \App\Models\ShippingSetting::for('biteship')->is_enabled;
    $areaProviders = [];
    if ($kaOn) {
        if ($btOn) $areaProviders['biteship'] = 'Biteship';
        $areaProviders['kiriminaja'] = 'KiriminAja';
    }
@endphp
<div class="border border-gray-200 rounded-xl p-3 bg-gray-50/50 space-y-3"
     id="ckw-root" data-cust="{{ $custId }}" data-wh="{{ $whId }}" data-target="{{ $targetSel ?? 'input[name=shipping_cost]' }}">

    {{-- Alamat tujuan --}}
    <div class="flex justify-between items-start gap-2">
        <div class="text-xs text-gray-600 min-w-0">
            <div class="font-semibold text-gray-700" id="ckw_addr_name">—</div>
            <div id="ckw_addr_line" class="text-gray-500 break-words">Memuat alamat…</div>
            <div id="ckw_addr_area" class="text-[11px] mt-0.5"></div>
        </div>
        <button type="button" id="ckw_addr_edit"
            class="shrink-0 text-[11px] px-2 py-1 border border-gray-300 rounded text-gray-600 hover:bg-gray-100">✎ Edit / Tambah Alamat</button>
    </div>

    {{-- Metode + berat + dimensi --}}
    <div class="flex items-end gap-2 flex-wrap">
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase mb-1">Metode</label>
            <select id="ckw_method" class="border border-gray-200 rounded-lg px-2 py-2 text-sm bg-white">
                <option value="kurir">Kurir</option>
                <option value="instant">Instant (Same Day)</option>
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-bold text-gray-400 uppercase mb-1">Berat (gram)</label>
            <input type="number" id="ckw_weight" value="1000" min="1" class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-24">
        </div>
        <button type="button" id="ckw_cek" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold">🔍 Cek Ongkir</button>
        <span id="ckw_hint" class="text-[11px] text-gray-400"></span>
    </div>
    <div class="flex items-end gap-1 flex-wrap">
        <label class="block text-[11px] font-bold text-gray-400 uppercase mr-1 self-center">Dimensi Paket (cm)</label>
        <input type="number" step="0.01" id="ckw_pl" min="0" placeholder="P" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-16 text-center">
        <span class="text-gray-400 self-center">×</span>
        <input type="number" step="0.01" id="ckw_pw" min="0" placeholder="L" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-16 text-center">
        <span class="text-gray-400 self-center">×</span>
        <input type="number" step="0.01" id="ckw_ph" min="0" placeholder="T" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-16 text-center">
        <span class="text-[11px] text-gray-400 ml-1 self-center">opsional — utk kurir instant (Pickup/Van)</span>
    </div>

    <div id="ckw_results" class="border border-gray-100 rounded-lg divide-y divide-gray-50 max-h-52 overflow-y-auto hidden text-sm bg-white"></div>
    <p class="text-[11px] text-gray-500" id="ckw_picked"></p>
</div>

{{-- Popup alamat (struktur sama dgn shipping-embed) --}}
<div id="ckw_modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-[520px] max-w-[95vw] max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-4 py-3 border-b">
            <h6 class="font-bold text-gray-800 text-sm">Alamat Pengiriman Pelanggan</h6>
            <button type="button" id="ckw_close" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div class="p-4 space-y-3">
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase mb-1">No. HP Penerima</label>
                <input type="text" id="ckw_phone" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            @include('partials.area-search', [
                'id'               => 'ckw_area',
                'url'              => url('/erp/api/shipping/areas'),
                'hiddenName'       => 'ckw_area_id_unused',
                'label'            => 'Cari Kota / Kecamatan / Kelurahan',
                'value'            => '',
                'text'             => '',
                'placeholder'      => 'Ketik nama daerah tujuan…',
                'postalTargetId'   => 'ckw_postal',
                'provinceTargetId' => 'ckw_province',
                'cityTargetId'     => 'ckw_city',
                'districtTargetId' => 'ckw_district',
                'providers'        => $areaProviders,
                'providerNames'    => ['biteship' => 'ckw_area_biteship_unused', 'kiriminaja' => 'ckw_area_kiriminaja_unused'],
            ])
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase mb-1">Detail Alamat</label>
                <textarea id="ckw_address" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Nama jalan, No. rumah, RT/RW, patokan…"></textarea>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase mb-1">Titik Lokasi <span class="text-gray-400 normal-case">(untuk kurir instant — opsional)</span></label>
                <input type="text" id="ckw_point" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Paste link Google Maps atau 'lat,long'">
            </div>
            <input type="hidden" id="ckw_province"><input type="hidden" id="ckw_city"><input type="hidden" id="ckw_district"><input type="hidden" id="ckw_postal">
        </div>
        <div class="flex justify-end gap-2 px-4 py-3 border-t bg-gray-50">
            <button type="button" id="ckw_cancel" class="px-4 py-2 text-sm font-bold text-gray-500 hover:bg-gray-100 rounded-lg">Batal</button>
            <button type="button" id="ckw_save" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 text-sm font-bold rounded-lg">Simpan Alamat</button>
        </div>
    </div>
</div>

<script>
(function () {
    const root = document.getElementById('ckw-root');
    if (!root) return;
    const $id = (x) => document.getElementById(x);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || '';
    const cid = root.dataset.cust, whId = root.dataset.wh, targetSel = root.dataset.target;
    const fmt = (n) => Math.round(n).toLocaleString('id-ID');
    const num = (v) => parseFloat(String(v).replace(/[^0-9.-]/g, '')) || 0;
    const esc = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    let area = '', areaKa = '', destLat = null, destLong = null;

    function areaInput(prov){
        return document.getElementById('ckw_area_' + prov + '_id')
            || (prov === 'biteship' ? document.getElementById('ckw_area_id') : null);
    }
    function areaVal(prov){ const el = areaInput(prov); return el ? el.value : ''; }
    function setAreaVal(prov, val){ const el = areaInput(prov); if (el) el.value = val || ''; }

    function renderAddr(d) {
        area = d.biteship_area_id || '';
        areaKa = d.kiriminaja_area_id || '';
        destLat = (d.latitude != null && d.latitude !== '') ? d.latitude : null;
        destLong = (d.longitude != null && d.longitude !== '') ? d.longitude : null;
        $id('ckw_addr_name').textContent = d.name || '—';
        $id('ckw_addr_line').textContent = d.full_address || 'Belum ada alamat. Klik Edit / Tambah Alamat.';
        const a = $id('ckw_addr_area');
        if (d.has_area) {
            a.textContent = '✓ Area siap' + (d.has_coordinate ? ' · 📍 titik siap (instant aktif)' : ' · titik lokasi belum diisi');
            a.className = 'text-[11px] mt-0.5 ' + (d.has_coordinate ? 'text-green-600' : 'text-gray-500');
        } else {
            a.textContent = '⚠ Area belum diset — cek ongkir butuh area. Klik Edit Alamat.';
            a.className = 'text-[11px] mt-0.5 text-amber-600';
        }
    }
    function loadAddr() {
        fetch('/erp/api/customers/' + cid + '/shipping', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(renderAddr).catch(() => {});
    }

    function openModal() {
        fetch('/erp/api/customers/' + cid + '/shipping', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(d => {
                $id('ckw_phone').value = d.recipient_phone || '';
                $id('ckw_address').value = d.shipping_address || '';
                $id('ckw_city').value = d.city || ''; $id('ckw_province').value = d.province || '';
                $id('ckw_district').value = d.district || ''; $id('ckw_postal').value = d.postal_code || '';
                setAreaVal('biteship', d.biteship_area_id || '');
                setAreaVal('kiriminaja', d.kiriminaja_area_id || '');
                if ($id('ckw_area_id')) $id('ckw_area_id').value = d.biteship_area_id || d.kiriminaja_area_id || '';
                $id('ckw_area_search').value = d.full_address || '';
                $id('ckw_point').value = d.location_point || '';
            }).catch(() => {});
        $id('ckw_modal').classList.remove('hidden'); $id('ckw_modal').classList.add('flex');
    }
    function closeModal() { $id('ckw_modal').classList.add('hidden'); $id('ckw_modal').classList.remove('flex'); }
    function saveAddr() {
        const payload = {
            recipient_phone: $id('ckw_phone').value, shipping_address: $id('ckw_address').value,
            city: $id('ckw_city').value, province: $id('ckw_province').value, district: $id('ckw_district').value,
            postal_code: $id('ckw_postal').value,
            biteship_area_id: areaVal('biteship'), kiriminaja_area_id: areaVal('kiriminaja'),
            location_point: $id('ckw_point').value,
        };
        fetch('/erp/api/customers/' + cid + '/shipping', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload),
        }).then(r => r.json()).then(d => { renderAddr(d); closeModal(); }).catch(() => alert('Gagal menyimpan alamat.'));
    }

    function cek() {
        if (!area && !areaKa) { $id('ckw_hint').textContent = 'Alamat belum punya area. Klik Edit Alamat.'; return; }
        const mode = $id('ckw_method').value === 'instant' ? 'instant' : 'regular';
        if (mode === 'instant' && (destLat == null || destLong == null)) {
            $id('ckw_hint').textContent = 'Kurir instant butuh Titik Lokasi. Klik Edit Alamat, isi Titik Lokasi.'; return;
        }
        $id('ckw_hint').textContent = 'Mengambil ongkir…';
        const params = { warehouse_id: whId, weight_gram: $id('ckw_weight').value || 1000, mode: mode };
        if (area)   params.destination_area_id = area;
        if (areaKa) params.destination_kiriminaja_id = areaKa;
        if (destLat != null && destLong != null) { params.destination_latitude = destLat; params.destination_longitude = destLong; }
        const pl = num($id('ckw_pl').value), pw = num($id('ckw_pw').value), ph = num($id('ckw_ph').value);
        if (pl > 0) params.package_length = pl; if (pw > 0) params.package_width = pw; if (ph > 0) params.package_height = ph;
        fetch('/erp/api/shipping/rates?' + new URLSearchParams(params).toString(), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(d => {
                const box = $id('ckw_results');
                if (!d.success || !d.rates.length) {
                    box.classList.add('hidden'); box.innerHTML = '';
                    $id('ckw_hint').textContent = (d.errors && d.errors.length) ? d.errors.join(' ') : 'Tidak ada layanan ongkir.';
                    return;
                }
                $id('ckw_hint').textContent = '';
                const groups = {};
                d.rates.forEach(r => { const c = r.courier_code || '-'; (groups[c] = groups[c] || { name: r.courier_name || c, items: [] }).items.push(r); });
                const arr = Object.values(groups).map(g => { g.items.sort((a, b) => (a.price || 0) - (b.price || 0)); g.min = g.items[0]?.price || 0; return g; });
                arr.sort((a, b) => a.min - b.min);
                let html = '';
                arr.forEach((g, gi) => g.items.forEach((r, ii) => {
                    const sep = (gi > 0 && ii === 0) ? ' border-t-2 border-gray-100' : '';
                    html += '<div class="px-3 py-1.5 cursor-pointer hover:bg-blue-50 flex justify-between gap-2 items-center ckw-row' + sep + '"'
                        + ' data-price="' + (r.price || 0) + '" data-name="' + ((g.name || '') + ' ' + (r.service_name || r.service_code || '')).replace(/"/g, '') + '">'
                        + '<span class="min-w-0 truncate"><span class="font-bold text-gray-700">' + esc(g.name) + '</span> <span class="text-gray-600">' + esc(r.service_name || r.service_code || '') + '</span></span>'
                        + '<span class="text-gray-400 text-[11px] whitespace-nowrap">' + esc(r.etd || '') + '</span>'
                        + '<span class="font-bold text-gray-800 whitespace-nowrap">Rp ' + fmt(r.price || 0) + '</span></div>';
                }));
                box.innerHTML = html; box.classList.remove('hidden');
            }).catch(() => { $id('ckw_hint').textContent = 'Gagal cek ongkir.'; });
    }

    function pick(row) {
        const price = num(row.dataset.price);
        const t = document.querySelector(targetSel);
        if (t) { t.value = Math.round(price); t.dispatchEvent(new Event('input', { bubbles: true })); }
        $id('ckw_picked').textContent = 'Dipilih: ' + (row.dataset.name || '') + ' — Rp ' + fmt(price);
        $id('ckw_results').classList.add('hidden');
    }

    $id('ckw_addr_edit').addEventListener('click', openModal);
    $id('ckw_close').addEventListener('click', closeModal);
    $id('ckw_cancel').addEventListener('click', closeModal);
    $id('ckw_save').addEventListener('click', saveAddr);
    $id('ckw_cek').addEventListener('click', cek);
    document.addEventListener('click', (e) => { const row = e.target.closest('.ckw-row'); if (row) pick(row); });
    loadAddr();
})();
</script>
