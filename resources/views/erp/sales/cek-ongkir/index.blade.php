@extends('layouts.erp')

@section('content')
<div class="max-w-screen-2xl mx-auto py-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Cek Ongkir</h1>
        <a href="{{ route('settings.integrations.index') }}" class="text-xs text-blue-600 hover:underline">⚙️ Setting Kurir</a>
    </div>

    @php
        // Provider tujuan mengikuti yang AKTIF di Pengaturan — halaman ini dulu terikat
        // ke RajaOngkir, sehingga tetap menyuruh mengisi API key RajaOngkir bahkan
        // setelah agregatornya diganti.
        $shippingManager = app(\App\Modules\Shipping\ShippingManager::class);
        $areaProviders   = $shippingManager->activeProviderOptions();
    @endphp
    @if(empty($areaProviders))
        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 rounded mb-4 text-sm">
            Belum ada kurir aktif. Aktifkan salah satu agregator di
            <a href="{{ route('settings.integrations.index') }}" class="font-bold underline">Settings → Integrasi</a> agar ongkir bisa diambil.
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    {{-- ══════════ KIRI: form cek ongkir + panel Buat SO ══════════ --}}
    <div class="space-y-6">
    <form action="{{ route('sales.cek-ongkir.check') }}" method="POST" class="bg-white rounded shadow p-4" id="ongkir-form">
        @csrf
        @php $mode = $input['mode'] ?? 'regular'; @endphp
        <input type="hidden" name="mode" id="ship_mode" value="{{ $mode }}">
        <input type="hidden" name="destination_latitude"  id="dest_lat"  value="{{ $input['destination_latitude']  ?? '' }}">
        <input type="hidden" name="destination_longitude" id="dest_long" value="{{ $input['destination_longitude'] ?? '' }}">

        {{-- MODE pengiriman: reguler vs instant --}}
        <div class="mb-4">
            <label class="block text-xs font-bold text-gray-500 mb-1">Mode Pengiriman</label>
            <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden text-sm">
                <button type="button" data-mode="regular"
                        class="mode-btn px-4 py-2 font-semibold {{ $mode === 'instant' ? 'bg-white text-gray-600' : 'bg-blue-600 text-white' }}">
                    Reguler
                </button>
                <button type="button" data-mode="instant"
                        class="mode-btn px-4 py-2 font-semibold border-l border-gray-200 {{ $mode === 'instant' ? 'bg-blue-600 text-white' : 'bg-white text-gray-600' }}">
                    ⚡ Instant
                </button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">Instant = kurir on-demand (Grab/GoSend/Lalamove), butuh Titik Lokasi tujuan.</p>
        </div>

        {{-- TITIK LOKASI: paste koordinat → reverse geocode jadi alamat + area --}}
        <div class="mb-4 {{ $mode === 'instant' ? '' : 'opacity-70' }}" id="coord_block">
            <label class="block text-xs font-bold text-gray-500 mb-1">
                Titik Lokasi Tujuan (koordinat)
                <span class="font-normal text-gray-400">— paste lalu “Lacak Alamat”, alamat &amp; area terisi otomatis</span>
            </label>
            <div class="flex gap-2">
                <input type="text" id="coord_input" autocomplete="off"
                       placeholder="Paste link Google Maps atau 'lat,long' (mis. -7.79,110.36)"
                       class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                <button type="button" id="resolve_btn"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm font-bold whitespace-nowrap">
                    📍 Lacak Alamat
                </button>
            </div>
            <p id="coord_hint" class="text-[11px] mt-1"></p>

            {{-- Peta interaktif (Leaflet + OSM): klik/geser pin atau cari alamat → koordinat ikut berubah.
                 Untuk kurir instant, titik inilah patokannya (bukan teks alamat — DB OSM bisa beda dgn Google Maps). --}}
            <div id="map_wrap" class="mt-2 hidden">
                <div id="map_canvas" class="w-full h-72 rounded-lg border border-gray-200 z-0"></div>
                <div class="flex items-center justify-between mt-1 gap-2">
                    <span class="text-[11px] text-gray-400">📍 Klik / geser pin atau cari alamat di peta untuk menyetel titik — inilah titik yang dipakai kurir instant.</span>
                    <a id="map_link" href="#" target="_blank" rel="noopener"
                       class="text-[11px] text-blue-600 hover:underline whitespace-nowrap">Buka di Google Maps ↗</a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 text-sm">
            {{-- ASAL: pilih gudang --}}
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">Gudang Asal (origin)</label>
                <select name="warehouse_id" id="warehouse_id" required
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
                    @forelse($warehouses as $w)
                        <option value="{{ $w->id }}"
                            data-address="{{ $w->fullAddress() }}"
                            data-ready="{{ $w->isShippingReady() ? '1' : '0' }}"
                            @selected((int)($input['warehouse_id'] ?? $selectedWarehouseId) === $w->id)>
                            {{ $w->name }}
                        </option>
                    @empty
                        <option value="">Belum ada gudang</option>
                    @endforelse
                </select>
                <p id="wh_address" class="text-[11px] text-gray-500 mt-1"></p>
                <p id="wh_warning" class="text-[11px] text-amber-600 mt-1 hidden">
                    Gudang ini belum punya alamat pengiriman.
                    <a href="{{ route('inventory.warehouses.index') }}" class="underline font-semibold">Lengkapi di Gudang</a>.
                </p>
            </div>

            {{-- TUJUAN: cari alamat --}}
            <div>
                @include('partials.area-search', [
                    'id'               => 'dest_area',
                    'url'              => route('sales.cek-ongkir.areas'),
                    'hiddenName'       => 'destination_area_id',
                    'label'            => 'Alamat Tujuan',
                    'value'            => $input['destination_area_id'] ?? '',
                    'text'             => $input['destination_label'] ?? '',
                    'placeholder'      => 'Ketik kelurahan / kecamatan / kota tujuan…',
                    'required'         => true,
                    'postalTargetId'   => 'dest_postal',
                    'provinceTargetId' => 'dest_province',
                    'cityTargetId'     => 'dest_city',
                    'districtTargetId' => 'dest_district',
                    'providers'        => $areaProviders,
                    'providerField'    => 'destination_provider',
                    'providerNames'    => [
                        'jubelio_shipment' => 'destination_jubelio_id',
                        'biteship'         => 'destination_area_id',
                        'kiriminaja'       => 'destination_kiriminaja_id',
                    ],
                    'providerValues'   => [
                        'jubelio_shipment' => $input['destination_jubelio_id'] ?? '',
                        'biteship'         => $input['destination_area_id'] ?? '',
                        'kiriminaja'       => $input['destination_kiriminaja_id'] ?? '',
                    ],
                ])
                <input type="hidden" name="destination_label" id="dest_label" value="{{ $input['destination_label'] ?? '' }}">
                {{-- Detail alamat (utk simpan ke pelanggan saat Buat SO). Kode pos ikut
                     DIKIRIM karena Jubelio Shipment mewajibkannya untuk hitung tarif. --}}
                <input type="hidden" name="destination_postal_code" id="dest_postal" value="{{ $input['destination_postal_code'] ?? '' }}">
                <input type="hidden" id="dest_province">
                <input type="hidden" id="dest_city">
                <input type="hidden" id="dest_district">
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">Berat (gram)</label>
                <input type="number" name="weight_gram" min="1" required
                       value="{{ $input['weight_gram'] ?? 1000 }}"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">Nilai Barang (Rp, opsional)</label>
                <input type="text" inputmode="numeric" name="item_value"
                       value="{{ $input['item_value'] ?? '' }}" placeholder="untuk estimasi asuransi"
                       class="rupiah-input w-full border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
            </div>

            {{-- DIMENSI PAKET (cm) — volumetrik utk kurir instant (Pickup/Van) & barang besar --}}
            <div class="col-span-2">
                <label class="block text-xs font-bold text-gray-500 mb-1">
                    Dimensi Paket (cm) <span class="font-normal text-gray-400">— opsional, utk volumetrik &amp; pilihan kendaraan instant</span>
                </label>
                <div class="flex items-center gap-1">
                    <input type="number" step="0.01" min="0" name="package_length" value="{{ $input['package_length'] ?? '' }}" placeholder="P"
                           class="w-20 border border-gray-200 rounded-lg px-2 py-2 text-center focus:outline-none focus:ring-2 focus:ring-blue-400">
                    <span class="text-gray-400">×</span>
                    <input type="number" step="0.01" min="0" name="package_width" value="{{ $input['package_width'] ?? '' }}" placeholder="L"
                           class="w-20 border border-gray-200 rounded-lg px-2 py-2 text-center focus:outline-none focus:ring-2 focus:ring-blue-400">
                    <span class="text-gray-400">×</span>
                    <input type="number" step="0.01" min="0" name="package_height" value="{{ $input['package_height'] ?? '' }}" placeholder="T"
                           class="w-20 border border-gray-200 rounded-lg px-2 py-2 text-center focus:outline-none focus:ring-2 focus:ring-blue-400">
                </div>
            </div>
        </div>
        <div class="flex justify-end mt-3">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-bold">🔍 Cek Ongkir</button>
        </div>
    </form>

        @if($rates !== null && count($rates))
        {{-- Panel pelanggan: pilih + simpan alamat, lalu Buat SO dari baris ongkir di kanan --}}
        <div class="bg-white rounded shadow p-4" id="so_panel">
            <div class="text-xs font-bold text-gray-500 mb-2">Buat Sales Order dari ongkir terpilih</div>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="relative">
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Pelanggan</label>
                    <div class="flex gap-2">
                        <input type="text" id="so_cust_search" autocomplete="off" placeholder="Cari nama / kode pelanggan…"
                               class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <button type="button" id="so_cust_add" class="bg-gray-100 hover:bg-gray-200 border border-gray-200 px-3 rounded-lg text-sm font-semibold whitespace-nowrap">+ Baru</button>
                    </div>
                    <input type="hidden" id="so_cust_id">
                    <div id="so_cust_results" class="absolute z-30 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto hidden text-sm"></div>
                    <p id="so_cust_hint" class="text-[11px] text-gray-400 mt-1"></p>
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">No. HP Penerima (opsional)</label>
                    <input type="text" id="so_phone" placeholder="08xx"
                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                </div>
            </div>
            <div class="flex items-center gap-2 mt-3 flex-wrap">
                <button type="button" id="so_save_addr" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded text-sm font-bold">💾 Simpan Alamat ke Pelanggan</button>
                <span id="so_addr_hint" class="text-[11px] text-gray-500"></span>
            </div>
            <p class="text-[11px] text-gray-400 mt-2">Pilih pelanggan &amp; simpan alamat tujuan, lalu klik <b>Buat SO</b> di baris ongkir yang diinginkan. Alamat + titik lokasi + ongkir akan terbawa ke form Sales Order.</p>
        </div>
        @endif
    </div>{{-- /kiri --}}

    {{-- ══════════ KANAN: daftar ongkir saja ══════════ --}}
    <div>
    @if(!empty($ongkirErrors) && is_array($ongkirErrors) && count($ongkirErrors))
        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-4 text-sm">
            @foreach($ongkirErrors as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    @if($rates !== null && count($rates))
        @php
            $selWh = collect($warehouses)->firstWhere('id', (int) ($input['warehouse_id'] ?? $selectedWarehouseId ?? 0));
            $originLabel = $selWh->name ?? 'Gudang Asal';
            $destLabel   = $input['destination_label'] ?? 'Tujuan';
            $wGram       = (float) ($input['weight_gram'] ?? 1000);
            $wKg         = rtrim(rtrim(number_format($wGram / 1000, 2, ',', '.'), '0'), ',');
            // Kelompokkan per kurir (JNE dgn JNE, J&T dgn J&T, dst).
            $groups = collect($rates)->groupBy('courier_code');
        @endphp
        {{-- Daftar ongkir gaya Berdu: list vertikal di samping form --}}
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
            <div class="text-center text-sm italic font-semibold text-gray-500 px-4 py-3 border-b border-gray-100">
                {{ $originLabel }} → {{ $destLabel }}
                <span class="text-gray-400 not-italic">({{ $wKg }} kg · {{ count($rates) }} layanan)</span>
            </div>
            <div>
                @foreach($groups as $courierCode => $services)
                    {{-- Satu grup = satu kurir; layanan-layanannya berderet di bawahnya. --}}
                    <div class="border-t-4 border-gray-100 first:border-t-0">
                        @php $cName = $services->first()['courier_name'] ?? null; @endphp
                        <div class="px-4 pt-2.5 pb-1 text-xs font-extrabold text-gray-700 uppercase tracking-wide">
                            {{ strtoupper($courierCode) }}@if($cName && strtoupper($cName) !== strtoupper($courierCode))<span class="font-semibold text-gray-400 normal-case"> · {{ $cName }}</span>@endif
                        </div>
                        @foreach($services as $r)
                            <div class="px-4 py-2 flex items-center gap-3 hover:bg-blue-50/50 transition border-t border-gray-50">
                                <div class="min-w-0 flex-1 text-[15px] text-gray-700 leading-tight">{{ $r['service_name'] ?: $r['service_code'] }}</div>
                                @if($r['etd'])
                                    <div class="text-[11px] text-gray-400 shrink-0 whitespace-nowrap">{{ $r['etd'] }}</div>
                                @endif
                                <div class="font-bold text-gray-900 text-right shrink-0 w-24">Rp {{ number_format($r['price'], 0, ',', '.') }}</div>
                                <button type="button" class="buat-so-btn shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded text-xs font-bold whitespace-nowrap"
                                        data-code="{{ $r['courier_code'] }}"
                                        data-service="{{ $r['service_code'] }}"
                                        data-name="{{ trim(($r['courier_name'] ?? '') . ' ' . ($r['service_name'] ?: $r['service_code'])) }}"
                                        data-price="{{ (int) $r['price'] }}">Buat SO →</button>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @elseif($rates !== null)
        <div class="bg-white rounded shadow px-3 py-6 text-center text-gray-400 text-sm">Tidak ada layanan ongkir ditemukan.</div>
    @else
        <div class="bg-white rounded-lg border border-dashed border-gray-200 px-4 py-20 text-center text-gray-400 text-sm">
            🚚 Isi form di kiri lalu klik <b>Cek Ongkir</b> — hasil ongkir tampil di sini.
        </div>
    @endif
    </div>{{-- /kanan --}}
    </div>{{-- /grid --}}
</div>

<script>
(function () {
    const sel     = document.getElementById('warehouse_id');
    const addrEl  = document.getElementById('wh_address');
    const warnEl  = document.getElementById('wh_warning');
    const form    = document.getElementById('ongkir-form');
    const destSearch = document.getElementById('dest_area_search');
    const destLabel  = document.getElementById('dest_label');

    function refreshWarehouse() {
        if (!sel || !sel.selectedOptions.length) return;
        const opt = sel.selectedOptions[0];
        addrEl.textContent = opt.getAttribute('data-address') || '';
        warnEl.classList.toggle('hidden', opt.getAttribute('data-ready') === '1');
    }
    if (sel) { sel.addEventListener('change', refreshWarehouse); refreshWarehouse(); }

    // Simpan teks tujuan agar tetap tampil setelah submit.
    if (form && destSearch && destLabel) {
        form.addEventListener('submit', function () { destLabel.value = destSearch.value; });
    }

    // ---- Mode pengiriman: reguler vs instant ----
    const modeInput  = document.getElementById('ship_mode');
    const coordBlock = document.getElementById('coord_block');
    function setMode(mode) {
        modeInput.value = mode;
        document.querySelectorAll('.mode-btn').forEach(function (b) {
            const on = b.getAttribute('data-mode') === mode;
            b.classList.toggle('bg-blue-600', on);
            b.classList.toggle('text-white', on);
            b.classList.toggle('bg-white', !on);
            b.classList.toggle('text-gray-600', !on);
        });
        if (coordBlock) coordBlock.classList.toggle('opacity-70', mode !== 'instant');
    }
    document.querySelectorAll('.mode-btn').forEach(function (b) {
        b.addEventListener('click', function () { setMode(b.getAttribute('data-mode')); });
    });

    // ---- Reverse geocode: paste koordinat → alamat + area ----
    const coordInput = document.getElementById('coord_input');
    const coordHint  = document.getElementById('coord_hint');
    const resolveBtn = document.getElementById('resolve_btn');
    const destId     = document.getElementById('dest_area_id');   // hidden area_id dari partial area-search
    const destHint   = document.getElementById('dest_area_hint');
    const latEl      = document.getElementById('dest_lat');
    const longEl     = document.getElementById('dest_long');

    function setHint(el, msg, cls) { if (el) { el.textContent = msg; el.className = 'text-[11px] mt-1 ' + cls; } }

    // Parse koordinat di browser (cermin parse_lat_long PHP) → peta tampil seketika.
    function parseLatLong(s) {
        s = String(s || '').trim();
        const L = '(-?\\d{1,3}\\.\\d+)';
        const pats = [
            new RegExp('!3d' + L + '!4d' + L),
            new RegExp('@' + L + ',' + L),
            new RegExp('[?&](?:q|ll|center|destination|daddr)=' + L + ',' + L),
            new RegExp(L + '\\s*,\\s*' + L),
        ];
        for (let i = 0; i < pats.length; i++) {
            const m = s.match(pats[i]);
            if (m) {
                const lat = parseFloat(m[1]), lng = parseFloat(m[2]);
                if (lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) return { lat: lat, lng: lng };
            }
        }
        return null;
    }

    // ---- Peta interaktif (Leaflet, dimuat on-demand dari CDN) ----
    let map = null, marker = null, rgTimer = null;

    function ensureLeaflet(cb) {
        if (window.L && window.L.Control && window.L.Control.Geocoder) { cb(); return; }
        function css(href){ if(!document.querySelector('link[data-leaflet="'+href+'"]')){ const l=document.createElement('link'); l.rel='stylesheet'; l.href=href; l.setAttribute('data-leaflet',href); document.head.appendChild(l);} }
        css('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        css('https://unpkg.com/leaflet-control-geocoder@2.4.0/dist/Control.Geocoder.css');
        function load(src, next){ const s=document.createElement('script'); s.src=src; s.onload=next; s.onerror=next; document.body.appendChild(s); }
        const geo = function(){ load('https://unpkg.com/leaflet-control-geocoder@2.4.0/dist/Control.Geocoder.js', cb); };
        if (window.L) geo(); else load('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', geo);
    }

    function initMap(lat, lng) {
        map = L.map('map_canvas').setView([lat, lng], 16);
        const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
        const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: '© Esri' });
        L.control.layers({ 'Peta': street, 'Satelit': sat }).addTo(map);
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function () { const p = marker.getLatLng(); onMapPoint(p.lat, p.lng); });
        map.on('click', function (e) { onMapPoint(e.latlng.lat, e.latlng.lng); });
        if (L.Control && L.Control.Geocoder) {
            L.Control.geocoder({ defaultMarkGeocode: false, placeholder: 'Cari alamat / koordinat…', geocoder: L.Control.Geocoder.nominatim() })
                .on('markgeocode', function (e) { const c = e.geocode.center; map.setView(c, 17); onMapPoint(c.lat, c.lng); })
                .addTo(map);
        }
        setTimeout(function () { map.invalidateSize(); }, 80);
    }

    // Setel koordinat ke semua input + pindahkan pin. TIDAK memanggil reverse geocode.
    function applyPoint(lat, lng, recenter) {
        lat = Math.round(parseFloat(lat) * 1e6) / 1e6;
        lng = Math.round(parseFloat(lng) * 1e6) / 1e6;
        if (isNaN(lat) || isNaN(lng)) return;
        latEl.value = lat; longEl.value = lng;
        coordInput.value = lat + ',' + lng;
        const ml = document.getElementById('map_link');
        if (ml) ml.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        setMode('instant');
        if (map && marker) { marker.setLatLng([lat, lng]); if (recenter) map.panTo([lat, lng]); }
    }

    // Tampilkan peta utk koordinat tertentu (buat map kalau belum ada).
    function showMap(lat, lng) {
        lat = parseFloat(lat); lng = parseFloat(lng);
        if (isNaN(lat) || isNaN(lng)) return;
        document.getElementById('map_wrap').classList.remove('hidden');
        ensureLeaflet(function () {
            if (!window.L) { setHint(coordHint, 'Peta gagal dimuat (cek koneksi). Koordinat tetap tersimpan.', 'text-amber-600'); return; }
            if (!map) initMap(lat, lng);
            applyPoint(lat, lng, true);
            setTimeout(function () { if (map) map.invalidateSize(); }, 80);
        });
    }

    // Dipicu saat user klik / geser pin / cari di peta: setel titik + refresh alamat (debounce).
    function onMapPoint(lat, lng) {
        applyPoint(lat, lng, false);
        setHint(coordHint, 'Titik dipindah — memperbarui alamat…', 'text-gray-400');
        clearTimeout(rgTimer);
        rgTimer = setTimeout(function () { reverseGeocode(lat, lng); }, 600);
    }

    // Ambil alamat + area Biteship dari koordinat (server: Nominatim + cari area).
    function reverseGeocode(lat, lng) {
        setHint(coordHint, 'Melacak alamat…', 'text-gray-400');
        resolveBtn.disabled = true;
        // Provider tujuan terpilih (multi-provider) → resolve area di provider yang sama.
        // Pemilih provider hanya dirender bila ada LEBIH DARI SATU provider aktif.
        // Saat cuma satu (kondisi normal sekarang), pakai default dari server —
        // dulu di-hardcode 'biteship' sehingga reverse geocode selalu gagal.
        const provSel = document.getElementById('dest_area_provider_sel');
        const prov = provSel ? provSel.value : @json($shippingManager->defaultProviderKey() ?? '');
        fetch(@json(route('sales.cek-ongkir.resolve')) + '?point=' + encodeURIComponent(lat + ',' + lng) + '&provider=' + encodeURIComponent(prov),
              { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                resolveBtn.disabled = false;
                if (!d.success) { setHint(coordHint, d.error || 'Gagal melacak alamat.', 'text-red-500'); return; }
                if (d.area_id) {
                    if (destId) destId.value = d.area_id;
                    const ph = document.getElementById('dest_area_' + prov + '_id');
                    if (ph) ph.value = d.area_id;
                    if (destSearch) destSearch.value = d.area_label || '';
                    if (destLabel)  destLabel.value  = d.area_label || '';
                    // Kode pos ikut disetel: Jubelio menolak hitung tarif tanpa ini.
                    const pc = document.getElementById('dest_postal');
                    if (pc && d.postal_code) pc.value = d.postal_code;
                    setHint(destHint, 'Area terpilih ✓' + (d.postal_code ? ' · ' + d.postal_code : ''), 'text-green-600');
                }
                if (d.address) {
                    setHint(coordHint, 'Perkiraan alamat (OSM): ' + d.address + ' — atau klik/geser pin di peta.',
                            d.area_id ? 'text-gray-500' : 'text-amber-600');
                } else {
                    setHint(coordHint, d.error || 'Titik tersimpan — verifikasi di peta.', 'text-amber-600');
                }
            })
            .catch(function () { resolveBtn.disabled = false; setHint(coordHint, 'Gagal melacak alamat.', 'text-red-500'); });
    }

    // Tombol "Lacak Alamat": parse teks koordinat → tampilkan di peta + cari alamat/area.
    function resolve() {
        const point = (coordInput.value || '').trim();
        if (!point) { setHint(coordHint, 'Paste koordinat / link Google Maps dulu.', 'text-amber-600'); return; }
        const local = parseLatLong(point);
        if (!local) { setHint(coordHint, 'Koordinat tidak terbaca. Pakai format "lat,long" atau link Google Maps.', 'text-red-500'); return; }
        showMap(local.lat, local.lng);
        reverseGeocode(local.lat, local.lng);
    }

    if (resolveBtn) resolveBtn.addEventListener('click', resolve);
    if (coordInput) coordInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); resolve(); }
    });

    // Setelah submit: jika koordinat tujuan masih ada, tampilkan kembali petanya.
    if (latEl && latEl.value && longEl && longEl.value) {
        if (coordInput && !coordInput.value) coordInput.value = latEl.value + ',' + longEl.value;
        showMap(latEl.value, longEl.value);
    }

    // ===== Buat SO: pilih pelanggan → simpan alamat → redirect ke form SO =====
    function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
    function pageCsrf(){ return document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value || ''; }
    const $g = (x) => document.getElementById(x);

    const csInput = $g('so_cust_search');
    if (csInput) {
        const csId = $g('so_cust_id'), csBox = $g('so_cust_results'), csHint = $g('so_cust_hint');
        const soPhone = $g('so_phone'), soAddrHint = $g('so_addr_hint');

        function pickCust(id, name){ csId.value = id; csInput.value = name || ''; csBox.classList.add('hidden');
            csHint.textContent = 'Pelanggan dipilih ✓'; csHint.className = 'text-[11px] text-green-600 mt-1'; }

        let t = null, last = 0;
        csInput.addEventListener('input', function(){
            csId.value = ''; csHint.textContent = '';
            const q = csInput.value.trim(); clearTimeout(t);
            if (q.length < 2){ csBox.classList.add('hidden'); csBox.innerHTML = ''; return; }
            t = setTimeout(function(){
                const rid = ++last;
                fetch('/erp/api/customers/search?q=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}})
                    .then(r=>r.json()).then(list=>{
                        if (rid !== last) return;
                        if (!Array.isArray(list) || !list.length){ csBox.innerHTML = '<div class="px-3 py-2 text-gray-400">Tidak ada hasil.</div>'; csBox.classList.remove('hidden'); return; }
                        csBox.innerHTML = '';
                        list.forEach(c=>{
                            const row = document.createElement('div');
                            row.className = 'px-3 py-2 cursor-pointer hover:bg-blue-50 border-b border-gray-50 last:border-0';
                            row.innerHTML = '<b>' + esc(c.code||'') + '</b> — ' + esc(c.name||'');
                            row.addEventListener('mousedown', function(e){ e.preventDefault(); pickCust(c.id, c.name); });
                            csBox.appendChild(row);
                        });
                        csBox.classList.remove('hidden');
                    }).catch(()=>{});
            }, 300);
        });
        document.addEventListener('click', function(e){ if (!e.target.closest('#so_panel')) csBox.classList.add('hidden'); });

        $g('so_cust_add')?.addEventListener('click', function(){
            const name = prompt('Nama pelanggan baru:');
            if (!name) return;
            fetch('/erp/customers/store-ajax', {method:'POST',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':pageCsrf()},
                body: JSON.stringify({name: name, phone: soPhone.value || null})})
                .then(r=>r.json()).then(d=>{ pickCust(d.id, d.name); csHint.textContent = 'Pelanggan baru dibuat ✓'; csHint.className = 'text-[11px] text-green-600 mt-1'; })
                .catch(()=>alert('Gagal membuat pelanggan.'));
        });

        function destPayload(){
            return {
                recipient_phone:  soPhone.value || '',
                shipping_address: ($g('dest_area_search')?.value || ''),
                province:         $g('dest_province')?.value || '',
                city:             $g('dest_city')?.value || '',
                district:         $g('dest_district')?.value || '',
                postal_code:      $g('dest_postal')?.value || '',
                biteship_area_id:   $g('dest_area_biteship_id')?.value || '',
                kiriminaja_area_id: $g('dest_area_kiriminaja_id')?.value || '',
                jubelio_area_id:    ($g('dest_area_jubelio_shipment_id') || $g('dest_area_id'))?.value || '',
                location_point:   (latEl.value && longEl.value) ? (latEl.value + ',' + longEl.value) : '',
            };
        }

        function saveAddrToCustomer(){
            return new Promise(function(resolve, reject){
                const cid = csId.value;
                if (!cid){ reject('Pilih pelanggan dulu.'); return; }
                const p = destPayload();
                if (!p.biteship_area_id && !p.kiriminaja_area_id && !p.jubelio_area_id && !p.postal_code && !p.location_point){ reject('Belum ada area / titik tujuan. Cek ongkir dulu.'); return; }
                fetch('/erp/api/customers/' + cid + '/shipping', {method:'POST',
                    headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':pageCsrf()},
                    body: JSON.stringify(p)})
                    .then(r=>r.json()).then(resolve).catch(()=>reject('Gagal menyimpan alamat.'));
            });
        }

        $g('so_save_addr')?.addEventListener('click', function(){
            soAddrHint.textContent = 'Menyimpan…'; soAddrHint.className = 'text-[11px] text-gray-400';
            saveAddrToCustomer().then(()=>{ soAddrHint.textContent = '✓ Alamat tersimpan ke pelanggan'; soAddrHint.className = 'text-[11px] text-green-600'; })
                .catch(msg=>{ soAddrHint.textContent = msg; soAddrHint.className = 'text-[11px] text-red-500'; csInput.focus(); });
        });

        function buatSO(btn){
            if (!csId.value){ soAddrHint.textContent = 'Pilih pelanggan dulu di panel ini.'; soAddrHint.className = 'text-[11px] text-red-500'; csInput.focus(); return; }
            btn.disabled = true; soAddrHint.textContent = 'Menyiapkan SO…'; soAddrHint.className = 'text-[11px] text-gray-400';
            saveAddrToCustomer().then(()=>{
                const mode = $g('ship_mode').value;
                const params = new URLSearchParams({
                    customer_id:           csId.value,
                    customer_name:         csInput.value,
                    delivery_method:       mode === 'instant' ? 'instant' : 'kurir',
                    weight_gram:           document.querySelector('[name="weight_gram"]')?.value || 1000,
                    shipping_gross:        btn.dataset.price || 0,
                    shipping_courier_code: btn.dataset.code || '',
                    shipping_service_code: btn.dataset.service || '',
                    shipping_service_name: btn.dataset.name || '',
                });
                const pl = document.querySelector('[name="package_length"]')?.value;
                const pw = document.querySelector('[name="package_width"]')?.value;
                const ph = document.querySelector('[name="package_height"]')?.value;
                if (pl) params.set('package_length', pl);
                if (pw) params.set('package_width', pw);
                if (ph) params.set('package_height', ph);
                window.location.href = @json(route('sales.orders.create')) + '?' + params.toString();
            }).catch(msg=>{ btn.disabled = false; soAddrHint.textContent = msg; soAddrHint.className = 'text-[11px] text-red-500'; csInput.focus(); });
        }
        document.addEventListener('click', function(e){ const b = e.target.closest('.buat-so-btn'); if (b) buatSO(b); });
    }
})();
</script>
@endsection
