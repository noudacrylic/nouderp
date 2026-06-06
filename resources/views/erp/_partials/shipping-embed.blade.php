@php
    /** Kartu Pengiriman embed untuk SO/Invoice. Param: $model (so/invoice, boleh null). */
    $m = $model ?? null;
    $sgGross = old('shipping_gross', $m->shipping_gross ?? 0);
    $sdType  = old('shipping_discount_type', $m->shipping_discount_type ?? 'nominal');
    $sdVal   = old('shipping_discount_value', $m->shipping_discount_value ?? 0);
    $scCode  = old('shipping_courier_code', $m->shipping_courier_code ?? '');
    $scSvc   = old('shipping_service_code', $m->shipping_service_code ?? '');
    $scName  = old('shipping_service_name', $m->shipping_service_name ?? '');
    $pkgL    = old('package_length', $m->package_length ?? '');
    $pkgW    = old('package_width',  $m->package_width ?? '');
    $pkgH    = old('package_height', $m->package_height ?? '');
    $fmtDim  = fn ($v) => ($v !== '' && $v !== null && (float) $v > 0) ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '';
    $pkDate  = old('pickup_date', ($m && $m->pickup_date) ? \Carbon\Carbon::parse($m->pickup_date)->format('Y-m-d') : '');
@endphp

<div class="card p-4 shadow-sm border border-gray-100 h-full" id="shipping-embed">
    <div class="mb-3">
        <span class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">🚚 Pengiriman & Ongkir</span>
    </div>

    {{-- Metode pengiriman (pindah dari header ke sini) --}}
    <div class="mb-3">
        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Metode Pengiriman</label>
        <select name="delivery_method" id="delivery_method"
            class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
            @foreach(\App\Modules\Sales\Models\SalesOrder::DELIVERY_METHODS as $val => $label)
                <option value="{{ $val }}" {{ old('delivery_method', $m->delivery_method ?? 'kurir') == $val ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Tanggal pengambilan (hanya muncul saat Ambil di Toko) --}}
    <div class="mb-3 hidden" id="ship_pickup_body">
        <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tanggal Pengambilan</label>
        <input type="date" name="pickup_date" id="pickup_date" value="{{ $pkDate }}"
            class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
        <p class="text-[11px] text-gray-400 mt-1">Perkiraan tanggal pelanggan mengambil pesanan di toko.</p>
    </div>

    {{-- Body kurir (disembunyikan saat Ambil di Toko) --}}
    <div id="ship_courier_body">
        {{-- Alamat tujuan --}}
        <div class="border border-gray-200 rounded-lg p-3 mb-3 bg-gray-50/50">
            <div class="flex justify-between items-start gap-2">
                <div class="text-xs text-gray-600 min-w-0">
                    <div class="font-semibold text-gray-700" id="ship_addr_name">—</div>
                    <div id="ship_addr_line" class="text-gray-500 break-words">Pilih pelanggan dulu untuk alamat tujuan.</div>
                    <div id="ship_addr_area" class="text-[11px] mt-0.5"></div>
                </div>
                <button type="button" id="btn_addr_edit"
                    class="shrink-0 text-[11px] px-2 py-1 border border-gray-300 rounded text-gray-600 hover:bg-gray-100">
                    ✎ Edit / Tambah Alamat
                </button>
            </div>
        </div>

        {{-- Cek ongkir --}}
        <div class="flex items-end gap-2 flex-wrap">
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Berat (gram)</label>
                <input type="number" id="ship_weight" value="1000" min="1"
                    class="form-control border border-gray-200 rounded-lg px-3 py-2 text-sm w-24">
            </div>
            <button type="button" id="btn_cek_ongkir"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold">
                🔍 Cek Ongkir
            </button>
            <span id="ongkir_hint" class="text-[11px] text-gray-400"></span>
        </div>

        {{-- Dimensi paket (manual) — untuk volumetrik kurir instant (Pickup/Van) & barang besar --}}
        <div class="flex items-end gap-1 flex-wrap mt-2">
            <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mr-1 self-center">Dimensi Paket (cm)</label>
            <input type="number" step="0.01" id="pkg_length" name="package_length" value="{{ $fmtDim($pkgL) }}" min="0" placeholder="P" class="form-control border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-16 text-center">
            <span class="text-gray-400 self-center">×</span>
            <input type="number" step="0.01" id="pkg_width" name="package_width" value="{{ $fmtDim($pkgW) }}" min="0" placeholder="L" class="form-control border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-16 text-center">
            <span class="text-gray-400 self-center">×</span>
            <input type="number" step="0.01" id="pkg_height" name="package_height" value="{{ $fmtDim($pkgH) }}" min="0" placeholder="T" class="form-control border border-gray-200 rounded-lg px-2 py-1.5 text-sm w-16 text-center">
            <span class="text-[11px] text-gray-400 ml-1 self-center">opsional — isi utk kurir instant (Pickup/Van) & barang besar</span>
        </div>

        <div id="ongkir_results" class="mt-2 border border-gray-100 rounded-lg divide-y divide-gray-50 max-h-52 overflow-y-auto hidden text-sm"></div>

        {{-- Ongkir & diskon --}}
        <div class="grid grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Ongkir (Rp)</label>
                <input type="text" id="shipping_gross_input" name="shipping_gross"
                    value="{{ number_format((float)$sgGross, 0, ',', '.') }}"
                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-right">
                <p id="ship_courier_picked" class="text-[11px] text-gray-400 mt-1">{{ $scCode ? strtoupper($scCode).' · '.$scName : '' }}</p>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Diskon Ongkir</label>
                <div class="flex gap-1">
                    <select id="ship_disc_type" name="shipping_discount_type"
                        class="border border-gray-200 rounded-lg px-2 py-2 text-sm w-16 bg-white">
                        <option value="nominal" {{ $sdType === 'nominal' ? 'selected' : '' }}>Rp</option>
                        <option value="percent" {{ $sdType === 'percent' ? 'selected' : '' }}>%</option>
                    </select>
                    <input type="text" id="ship_disc_value" name="shipping_discount_value"
                        value="{{ number_format((float)$sdVal, 0, ',', '.') }}"
                        class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-right">
                </div>
            </div>
        </div>
        <div class="text-[11px] text-gray-500 mt-2">Ongkir bersih (ditagih ke pelanggan): <span id="ship_net_display" class="font-bold text-gray-700">Rp 0</span></div>
    </div>

    {{-- Pesan saat Ambil di Toko --}}
    <div id="ship_pickup_note" class="text-xs text-gray-500 italic hidden">Metode Ambil di Toko — tanpa ongkir kurir.</div>

    <input type="hidden" name="shipping_courier_code" id="shipping_courier_code" value="{{ $scCode }}">
    <input type="hidden" name="shipping_service_code" id="shipping_service_code" value="{{ $scSvc }}">
    <input type="hidden" name="shipping_service_name" id="shipping_service_name" value="{{ $scName }}">
</div>

{{-- ===== POPUP ALAMAT ===== --}}
<div id="addrModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-xl shadow-2xl w-[520px] max-w-[95vw] max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-4 py-3 border-b">
            <h6 class="font-bold text-gray-800 text-sm">Alamat Pengiriman Pelanggan</h6>
            <button type="button" id="addr_close" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div class="p-4 space-y-3">
            <div id="addr_no_customer" class="bg-amber-50 border border-amber-200 text-amber-700 text-xs px-3 py-2 rounded hidden">
                Pilih customer dulu sebelum mengisi alamat.
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">No. HP Penerima</label>
                <input type="text" id="addr_phone" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>

            {{-- Satu pencarian area: auto-isi provinsi/kota/kecamatan/kode pos --}}
            @include('partials.area-search', [
                'id'               => 'cust_area',
                'url'              => url('/erp/api/shipping/areas'),
                'hiddenName'       => 'cust_area_id_unused',
                'label'            => 'Cari Kota / Kecamatan / Kelurahan',
                'value'            => '',
                'text'             => '',
                'placeholder'      => 'Ketik nama daerah tujuan…',
                'postalTargetId'   => 'addr_postal',
                'provinceTargetId' => 'addr_province',
                'cityTargetId'     => 'addr_city',
                'districtTargetId' => 'addr_district',
            ])
            <p class="text-[11px] text-gray-400 -mt-1">Provinsi, kota, kecamatan & kode pos terisi otomatis dari pilihan di atas.</p>

            {{-- Detail jalan saja --}}
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Detail Alamat</label>
                <textarea id="addr_address" rows="2" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" style="min-height:0" placeholder="Nama jalan, No. rumah, RT/RW, patokan…"></textarea>
            </div>

            {{-- Titik lokasi (untuk kurir instant: Grab/GoSend/Lalamove) --}}
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Titik Lokasi <span class="text-gray-400 normal-case">(untuk kurir instant — opsional)</span></label>
                <div class="flex gap-2">
                    <input type="text" id="addr_location_point" class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                           placeholder="Paste link Google Maps atau 'lat,long' (mis. -7.79,110.36)">
                    <button type="button" id="addr_point_btn"
                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg text-sm font-bold whitespace-nowrap">📍 Peta</button>
                </div>
                <p class="text-[11px] text-gray-400 mt-0.5">Wajib jika mau pakai kurir <b>instant</b> (Grab/GoSend/Lalamove). Paste link/koordinat lalu klik 📍 Peta — atau langsung klik / geser pin di peta.</p>

                {{-- Peta interaktif: klik/geser pin atau cari alamat → koordinat ikut berubah. --}}
                <div id="addr_map_wrap" class="mt-2 hidden">
                    <div id="addr_map" class="w-full h-64 rounded-lg border border-gray-200 z-0"></div>
                    <div class="flex items-center justify-between mt-1 gap-2">
                        <span id="addr_map_hint" class="text-[11px] text-gray-400">Klik / geser pin atau cari alamat untuk menyetel titik.</span>
                        <a id="addr_map_link" href="#" target="_blank" rel="noopener"
                           class="text-[11px] text-blue-600 hover:underline whitespace-nowrap">Buka di Google Maps ↗</a>
                    </div>
                </div>
            </div>

            {{-- Auto-terisi dari pencarian area (tersembunyi) --}}
            <input type="hidden" id="addr_province">
            <input type="hidden" id="addr_city">
            <input type="hidden" id="addr_district">
            <input type="hidden" id="addr_postal">
        </div>
        <div class="flex justify-end gap-2 px-4 py-3 border-t bg-gray-50">
            <button type="button" id="addr_cancel" class="px-4 py-2 text-sm font-bold text-gray-500 hover:bg-gray-100 rounded-lg">Batal</button>
            <button type="button" id="addr_save" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 text-sm font-bold rounded-lg">Simpan Alamat</button>
        </div>
    </div>
</div>

<script>
(function () {
    const embed = document.getElementById('shipping-embed');
    if (!embed) return;

    const $id = (x) => document.getElementById(x);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
               || document.querySelector('input[name="_token"]')?.value || '';

    function num(v){ return (typeof cleanNumber === 'function') ? cleanNumber(v) : (parseFloat(String(v).replace(/[^0-9.-]/g,''))||0); }
    function fmt(n){ return (typeof formatIDR === 'function') ? formatIDR(n) : Math.round(n).toLocaleString('id-ID'); }
    function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

    // ---- state alamat customer terpilih ----
    let area = ''; // biteship_area_id customer
    let destLat = null, destLong = null; // koordinat tujuan (untuk kurir instant)

    function currentCustomerId(){ return ($id('customer_id')?.value || '').trim(); }

    function applyMethod(){
        const dm = $id('delivery_method')?.value || 'kurir';
        const pickup = dm === 'ambil_toko';
        $id('ship_courier_body').classList.toggle('hidden', pickup);
        $id('ship_pickup_note').classList.toggle('hidden', !pickup);
        $id('ship_pickup_body')?.classList.toggle('hidden', !pickup);
        if (pickup) {
            const shipEl = $id('shipping');
            if (shipEl){ shipEl.value = '0'; shipEl.dataset.raw = 0; shipEl.readOnly = true; }
            if (typeof recalculate === 'function') recalculate();
        } else {
            // Ganti metode → daftar kurir beda (instant vs reguler), bersihkan hasil lama.
            const box = $id('ongkir_results'); if (box){ box.classList.add('hidden'); box.innerHTML = ''; }
            $id('ongkir_hint').textContent = (dm === 'instant')
                ? 'Mode instant — klik Cek Ongkir untuk Grab/GoSend/Lalamove (butuh Titik Lokasi).'
                : '';
            computeNet();
        }
    }

    function computeNet(){
        const gross = num($id('shipping_gross_input').value);
        const dtype = $id('ship_disc_type').value;
        const dval  = num($id('ship_disc_value').value);
        const disc  = dtype === 'percent' ? gross * dval / 100 : dval;
        const net   = Math.max(0, gross - disc);
        const shipEl = $id('shipping'); // field Shipping di summary
        if (shipEl){ shipEl.value = fmt(net); shipEl.dataset.raw = net; shipEl.readOnly = true; shipEl.classList.add('bg-gray-100'); }
        $id('ship_net_display').textContent = 'Rp ' + fmt(net);
        if (typeof recalculate === 'function') recalculate();
    }

    // Dipakai saat Invoice memuat SO / SO memuat Penawaran.
    window.setShippingDetail = function(d){
        if (!d) return;
        if (d.gross != null) $id('shipping_gross_input').value = fmt(num(d.gross));
        if (d.discount_type) $id('ship_disc_type').value = d.discount_type;
        if (d.discount_value != null) $id('ship_disc_value').value = fmt(num(d.discount_value));
        if (d.courier_code != null) $id('shipping_courier_code').value = d.courier_code;
        if (d.service_code != null) $id('shipping_service_code').value = d.service_code;
        if (d.service_name != null) {
            $id('shipping_service_name').value = d.service_name;
            $id('ship_courier_picked').textContent = d.courier_code ? (String(d.courier_code).toUpperCase() + ' · ' + (d.service_name||'')) : '';
        }
        if (d.package_length != null && $id('pkg_length'))  $id('pkg_length').value  = num(d.package_length) > 0 ? d.package_length : '';
        if (d.package_width  != null && $id('pkg_width'))   $id('pkg_width').value   = num(d.package_width)  > 0 ? d.package_width  : '';
        if (d.package_height != null && $id('pkg_height'))  $id('pkg_height').value  = num(d.package_height) > 0 ? d.package_height : '';
        computeNet();
    };

    // ---- muat alamat customer ----
    function renderAddress(d){
        area = d.biteship_area_id || '';
        destLat  = (d.latitude != null && d.latitude !== '') ? d.latitude : null;
        destLong = (d.longitude != null && d.longitude !== '') ? d.longitude : null;
        $id('ship_addr_name').textContent = d.name || '—';
        $id('ship_addr_line').textContent = d.full_address || 'Belum ada alamat. Klik Edit / Tambah Alamat.';
        const areaEl = $id('ship_addr_area');
        if (d.has_area){
            const coordNote = d.has_coordinate ? ' · 📍 titik siap (kurir instant aktif)' : ' · titik lokasi belum diisi (kurir instant tak muncul)';
            areaEl.textContent = '✓ Area siap untuk cek ongkir' + coordNote;
            areaEl.className = 'text-[11px] mt-0.5 ' + (d.has_coordinate ? 'text-green-600' : 'text-gray-500');
        } else {
            areaEl.textContent = '⚠ Area belum diset — cek ongkir butuh area. Klik Edit Alamat.';
            areaEl.className = 'text-[11px] mt-0.5 text-amber-600';
        }
    }
    window.reloadShippingAddress = function(){
        const cid = currentCustomerId();
        if (!cid){ renderAddress({}); return; }
        fetch('/erp/api/customers/' + cid + '/shipping', {headers:{'Accept':'application/json'}})
            .then(r => r.json()).then(renderAddress).catch(()=>{});
    };

    // ---- popup alamat ----
    function openModal(){
        if (!currentCustomerId()){ $id('addr_no_customer').classList.remove('hidden'); }
        else { $id('addr_no_customer').classList.add('hidden'); }
        // prefill dari data tampil saat ini
        fetch('/erp/api/customers/' + (currentCustomerId()||0) + '/shipping', {headers:{'Accept':'application/json'}})
            .then(r=>r.json()).then(d=>{
                $id('addr_phone').value    = d.recipient_phone || '';
                $id('addr_address').value  = d.shipping_address || '';
                $id('addr_city').value     = d.city || '';
                $id('addr_province').value = d.province || '';
                $id('addr_district').value = d.district || '';
                $id('addr_postal').value   = d.postal_code || '';
                $id('cust_area_id').value  = d.biteship_area_id || '';
                $id('cust_area_search').value = d.full_address || '';
                $id('addr_location_point').value = d.location_point || '';
                // Tampilkan peta jika sudah ada titik tersimpan.
                const c = d.location_point ? parseLatLong(d.location_point) : null;
                if (c){ showAddrMap(c.lat, c.lng); }
                else { $id('addr_map_wrap').classList.add('hidden'); $id('addr_map_hint').textContent = 'Klik / geser pin atau cari alamat untuk menyetel titik.'; }
            }).catch(()=>{});
        $id('addrModal').classList.remove('hidden');
        $id('addrModal').classList.add('flex');
    }
    function closeModal(){ $id('addrModal').classList.add('hidden'); $id('addrModal').classList.remove('flex'); }

    function saveAddress(){
        const cid = currentCustomerId();
        if (!cid){ alert('Pilih customer dulu.'); return; }
        const payload = {
            recipient_phone:  $id('addr_phone').value,
            shipping_address: $id('addr_address').value,
            city:             $id('addr_city').value,
            province:         $id('addr_province').value,
            district:         $id('addr_district').value,
            postal_code:      $id('addr_postal').value,
            biteship_area_id: $id('cust_area_id').value,
            location_point:   $id('addr_location_point').value,
        };
        fetch('/erp/api/customers/' + cid + '/shipping', {
            method:'POST',
            headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
            body: JSON.stringify(payload),
        }).then(r=>r.json()).then(d=>{ renderAddress(d); closeModal(); }).catch(()=>alert('Gagal menyimpan alamat.'));
    }

    // ---- Peta interaktif titik lokasi (Leaflet, dimuat on-demand) ----
    let aMap = null, aMarker = null, aRgTimer = null;

    function ensureLeaflet(cb){
        if (window.L && window.L.Control && window.L.Control.Geocoder) { cb(); return; }
        function css(href){ if(!document.querySelector('link[data-leaflet="'+href+'"]')){ const l=document.createElement('link'); l.rel='stylesheet'; l.href=href; l.setAttribute('data-leaflet',href); document.head.appendChild(l);} }
        css('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        css('https://unpkg.com/leaflet-control-geocoder@2.4.0/dist/Control.Geocoder.css');
        function load(src, next){ const s=document.createElement('script'); s.src=src; s.onload=next; s.onerror=next; document.body.appendChild(s); }
        const geo = function(){ load('https://unpkg.com/leaflet-control-geocoder@2.4.0/dist/Control.Geocoder.js', cb); };
        if (window.L) geo(); else load('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', geo);
    }

    function parseLatLong(s){
        s = String(s||'').trim();
        const L = '(-?\\d{1,3}\\.\\d+)';
        const pats = [ new RegExp('!3d'+L+'!4d'+L), new RegExp('@'+L+','+L),
                       new RegExp('[?&](?:q|ll|center|destination|daddr)='+L+','+L), new RegExp(L+'\\s*,\\s*'+L) ];
        for (let i=0;i<pats.length;i++){ const m=s.match(pats[i]); if(m){ const lat=parseFloat(m[1]),lng=parseFloat(m[2]);
            if(lat>=-90&&lat<=90&&lng>=-180&&lng<=180) return {lat:lat,lng:lng}; } }
        return null;
    }

    function applyAddrPoint(lat, lng, recenter){
        lat = Math.round(parseFloat(lat)*1e6)/1e6; lng = Math.round(parseFloat(lng)*1e6)/1e6;
        if (isNaN(lat)||isNaN(lng)) return;
        $id('addr_location_point').value = lat + ',' + lng;
        destLat = lat; destLong = lng;
        const ml = $id('addr_map_link'); if (ml) ml.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        if (aMap && aMarker){ aMarker.setLatLng([lat,lng]); if (recenter) aMap.panTo([lat,lng]); }
    }

    function initAddrMap(lat, lng){
        aMap = L.map('addr_map').setView([lat, lng], 16);
        const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(aMap);
        const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' });
        L.control.layers({ 'Peta': street, 'Satelit': sat }).addTo(aMap);
        aMarker = L.marker([lat, lng], { draggable: true }).addTo(aMap);
        aMarker.on('dragend', function(){ const p = aMarker.getLatLng(); onAddrPoint(p.lat, p.lng); });
        aMap.on('click', function(e){ onAddrPoint(e.latlng.lat, e.latlng.lng); });
        if (L.Control && L.Control.Geocoder){
            L.Control.geocoder({ defaultMarkGeocode:false, placeholder:'Cari alamat / koordinat…', geocoder:L.Control.Geocoder.nominatim() })
                .on('markgeocode', function(e){ const c=e.geocode.center; aMap.setView(c,17); onAddrPoint(c.lat,c.lng); }).addTo(aMap);
        }
        setTimeout(function(){ aMap.invalidateSize(); }, 80);
    }

    function showAddrMap(lat, lng){
        lat = parseFloat(lat); lng = parseFloat(lng);
        if (isNaN(lat)||isNaN(lng)) return;
        $id('addr_map_wrap').classList.remove('hidden');
        ensureLeaflet(function(){
            if (!window.L){ $id('addr_map_hint').textContent = 'Peta gagal dimuat (cek koneksi). Koordinat tetap tersimpan.'; return; }
            if (!aMap) initAddrMap(lat, lng);
            applyAddrPoint(lat, lng, true);
            setTimeout(function(){ if (aMap) aMap.invalidateSize(); }, 80);
        });
    }

    // Klik / geser pin / cari → setel titik + refresh alamat & area (debounce).
    function onAddrPoint(lat, lng){
        applyAddrPoint(lat, lng, false);
        $id('addr_map_hint').textContent = 'Memperbarui alamat…';
        clearTimeout(aRgTimer);
        aRgTimer = setTimeout(function(){ addrReverse(lat, lng); }, 600);
    }

    function addrReverse(lat, lng){
        fetch('/erp/api/shipping/reverse-geocode?point=' + encodeURIComponent(lat + ',' + lng), {headers:{'Accept':'application/json'}})
            .then(r=>r.json()).then(function(d){
                if (!d.success){ $id('addr_map_hint').textContent = d.error || 'Gagal melacak alamat.'; return; }
                // Isi area otomatis hanya jika belum dipilih (jangan timpa pilihan user).
                if (d.area_id && !$id('cust_area_id').value){
                    $id('cust_area_id').value = d.area_id;
                    $id('cust_area_search').value = d.area_label || '';
                    if (d.postal_code) $id('addr_postal').value = d.postal_code;
                }
                $id('addr_map_hint').textContent = d.address ? ('📍 ' + d.address) : 'Titik lokasi tersetel.';
            }).catch(function(){ $id('addr_map_hint').textContent = 'Gagal melacak alamat.'; });
    }

    // Tombol 📍 Peta: parse isi field → tampilkan di peta + lacak alamat.
    function showAddrPointFromInput(){
        const p = ($id('addr_location_point').value || '').trim();
        if (!p){ showAddrMap(-6.2, 106.816667); $id('addr_map_hint').textContent = 'Cari alamat atau klik di peta untuk menyetel titik.'; return; }
        const c = parseLatLong(p);
        if (!c){ $id('addr_map_hint').textContent = 'Koordinat tidak terbaca. Pakai "lat,long" atau link Google Maps.'; $id('addr_map_wrap').classList.remove('hidden'); return; }
        showAddrMap(c.lat, c.lng);
        addrReverse(c.lat, c.lng);
    }

    // ---- auto berat dari item produk ----
    let weightTimer = null;
    let weightManual = false; // user mengetik manual → jangan ditimpa
    function collectItems(){
        const rows = document.querySelectorAll('#items .item-row');
        const items = [];
        rows.forEach(r => {
            const pid = r.querySelector('.product_id')?.value;
            if (!pid) return;
            items.push({
                product_id: pid,
                qty: r.querySelector('.qty')?.value || 0,
                unit_id: r.querySelector('.unit-select')?.value || '',
            });
        });
        return items;
    }
    function recalcWeight(){
        if (weightManual) return;
        const items = collectItems();
        if (!items.length) return;
        fetch('/erp/api/shipping/weight', {
            method:'POST',
            headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
            body: JSON.stringify({items}),
        }).then(r=>r.json()).then(d=>{
            if (d.weight_gram > 0 && !weightManual){ $id('ship_weight').value = d.weight_gram; }
        }).catch(()=>{});
    }
    function scheduleWeight(){ clearTimeout(weightTimer); weightTimer = setTimeout(recalcWeight, 500); }

    // ---- cek ongkir ----
    function cekOngkir(){
        const cid = currentCustomerId();
        if (!cid){ $id('ongkir_hint').textContent = 'Pilih customer dulu.'; return; }
        if (!area){ $id('ongkir_hint').textContent = 'Alamat customer belum punya area. Klik Edit Alamat.'; return; }

        // mode kurir: 'instant' → hanya kurir instant; selain itu → kurir reguler (kecuali instant).
        const dm = $id('delivery_method')?.value || 'kurir';
        const mode = dm === 'instant' ? 'instant' : 'regular';
        if (mode === 'instant' && (destLat == null || destLong == null)){
            $id('ongkir_hint').textContent = 'Kurir instant butuh Titik Lokasi. Klik Edit / Tambah Alamat, isi Titik Lokasi.';
            return;
        }

        const wh = document.querySelector('[name="warehouse_id"]')?.value;
        const weight = $id('ship_weight').value || 1000;
        $id('ongkir_hint').textContent = 'Mengambil ongkir…';
        const params = {warehouse_id: wh, destination_area_id: area, weight_gram: weight, mode: mode};
        // Koordinat tujuan → kurir instant (Grab/GoSend/Lalamove) ikut muncul.
        if (destLat != null && destLong != null){ params.destination_latitude = destLat; params.destination_longitude = destLong; }
        // Dimensi paket manual (volumetrik) → pilihan kendaraan instant yang tepat (Pickup utk barang besar).
        const pl = num($id('pkg_length')?.value), pw = num($id('pkg_width')?.value), ph = num($id('pkg_height')?.value);
        if (pl > 0) params.package_length = pl;
        if (pw > 0) params.package_width  = pw;
        if (ph > 0) params.package_height = ph;
        const qs = new URLSearchParams(params);
        fetch('/erp/api/shipping/rates?' + qs.toString(), {headers:{'Accept':'application/json'}})
            .then(r=>r.json()).then(d=>{
                const box = $id('ongkir_results');
                if (!d.success || !d.rates.length){
                    box.classList.add('hidden'); box.innerHTML='';
                    $id('ongkir_hint').textContent = (d.errors && d.errors.length) ? d.errors.join(' ') : 'Tidak ada layanan ongkir.';
                    return;
                }
                $id('ongkir_hint').textContent = '';

                // Kelompokkan per kurir; grup diurut termurah dulu, layanan dalam grup juga termurah dulu.
                const groups = {};
                d.rates.forEach(r => {
                    const code = (r.courier_code || '-');
                    if (!groups[code]) groups[code] = { code: code, name: r.courier_name || code, items: [] };
                    groups[code].items.push(r);
                });
                const arr = Object.values(groups).map(g => {
                    g.items.sort((a, b) => (a.price || 0) - (b.price || 0));
                    g.min = g.items[0] ? (g.items[0].price || 0) : 0;
                    return g;
                });
                arr.sort((a, b) => a.min - b.min);

                // Kompak: courier + service satu baris (mis. "ANTERAJA Next Day"), tanpa baris header.
                // Tetap dikelompokkan per kurir (garis pemisah tipis antar grup).
                let html = '';
                arr.forEach((g, gi) => {
                    g.items.forEach((r, ii) => {
                        const sep = (gi > 0 && ii === 0) ? ' border-t-2 border-gray-100' : '';
                        const label = ((r.courier_name || g.name || '') + ' ' + (r.service_name || r.service_code || '')).replace(/"/g, '');
                        html += '<div class="px-3 py-1.5 cursor-pointer hover:bg-blue-50 flex justify-between gap-2 items-center ongkir-row' + sep + '"'
                            + ' data-code="' + (r.courier_code || '') + '" data-service="' + (r.service_code || '') + '" data-name="' + label + '" data-price="' + (r.price || 0) + '">'
                            + '<span class="min-w-0 truncate"><span class="font-bold text-gray-700">' + esc(g.name) + '</span> <span class="text-gray-600">' + esc(r.service_name || r.service_code || '') + '</span></span>'
                            + '<span class="text-gray-400 text-[11px] whitespace-nowrap">' + esc(r.etd || '') + '</span>'
                            + '<span class="font-bold text-gray-800 whitespace-nowrap">Rp ' + fmt(r.price || 0) + '</span></div>';
                    });
                });
                box.innerHTML = html;
                box.classList.remove('hidden');
            }).catch(()=>{ $id('ongkir_hint').textContent = 'Gagal cek ongkir.'; });
    }

    function pickCourier(row){
        $id('shipping_gross_input').value = fmt(num(row.dataset.price));
        $id('shipping_courier_code').value = row.dataset.code || '';
        $id('shipping_service_code').value = row.dataset.service || '';
        $id('shipping_service_name').value = row.dataset.name || '';
        $id('ship_courier_picked').textContent = (row.dataset.code||'').toUpperCase() + ' · ' + (row.dataset.name||'');
        $id('ongkir_results').classList.add('hidden');
        computeNet();
    }

    document.addEventListener('DOMContentLoaded', function(){
        $id('btn_addr_edit').addEventListener('click', openModal);
        $id('addr_close').addEventListener('click', closeModal);
        $id('addr_cancel').addEventListener('click', closeModal);
        $id('addr_save').addEventListener('click', saveAddress);
        $id('addr_point_btn')?.addEventListener('click', showAddrPointFromInput);
        $id('addr_location_point')?.addEventListener('change', function(){
            const c = parseLatLong(this.value || '');
            if (c) showAddrMap(c.lat, c.lng);
        });
        $id('btn_cek_ongkir').addEventListener('click', cekOngkir);
        $id('shipping_gross_input').addEventListener('input', computeNet);
        $id('ship_disc_value').addEventListener('input', computeNet);
        $id('ship_disc_type').addEventListener('change', computeNet);
        $id('delivery_method')?.addEventListener('change', applyMethod);
        document.addEventListener('click', function(e){
            const row = e.target.closest('.ongkir-row');
            if (row) pickCourier(row);
        });

        // Auto berat dari item: hitung ulang saat item/qty/unit berubah.
        $id('ship_weight').addEventListener('input', function(){ weightManual = true; });
        document.addEventListener('input', function(e){ if (e.target.closest && e.target.closest('#items')) scheduleWeight(); });
        document.addEventListener('change', function(e){ if (e.target.closest && e.target.closest('#items')) scheduleWeight(); });
        document.addEventListener('click', function(e){ if (e.target.closest && e.target.closest('.product-item')) setTimeout(scheduleWeight, 600); });

        window.reloadShippingAddress();
        applyMethod();
        setTimeout(recalcWeight, 1000); // item yang sudah terisi (edit / dari SO)
    });
})();
</script>
