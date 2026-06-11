@php
    /**
     * Autocomplete area kurir → area id. Mendukung 1 provider (legacy) atau banyak provider
     * (Biteship + KiriminAja) dengan pemilih provider dalam SATU kotak pencarian.
     *
     * Param dasar (mode 1 provider — legacy, tetap kompatibel):
     *   $id              string  basis id DOM unik (wajib)
     *   $url             string  endpoint pencarian area (wajib)
     *   $hiddenName      string  name input tersembunyi penyimpan area id (wajib)
     *   $label, $value, $text, $placeholder, $required
     *   $postalTargetId / $provinceTargetId / $cityTargetId / $districtTargetId  ?string
     *
     * Param multi-provider (opsional — aktif bila $providers diisi):
     *   $providers       array   [code => label], mis. ['biteship'=>'Biteship','kiriminaja'=>'KiriminAja']
     *   $providerNames   array   [code => name attr input hidden] (untuk submit form). Default: code.'_area_id'
     *   $providerValues  array   [code => nilai area id tersimpan]
     *   $providerField   ?string name hidden penyimpan provider terpilih (mis. 'destination_provider')
     *   $url dipakai sebagai base; provider dikirim via ?provider=...
     *
     * Catatan: dalam mode multi, hidden per-provider ber-id "{id}_{code}_id"; hidden "{id}_id"
     * tetap ada sebagai cermin provider yang sedang dipilih (untuk JS lama yang membacanya).
     */
    $label            = $label ?? 'Cari Alamat';
    $value            = $value ?? '';
    $text             = $text ?? '';
    $placeholder      = $placeholder ?? 'Ketik kelurahan / kecamatan / kota…';
    $required         = $required ?? false;
    $postalTargetId   = $postalTargetId ?? null;
    $provinceTargetId = $provinceTargetId ?? null;
    $cityTargetId     = $cityTargetId ?? null;
    $districtTargetId = $districtTargetId ?? null;

    $providers      = $providers ?? [];
    $multi          = is_array($providers) && count($providers) > 0;
    $providerNames  = $providerNames ?? [];
    $providerValues = $providerValues ?? [];
    $providerField  = $providerField ?? null;
    $firstProvider  = $multi ? array_key_first($providers) : 'biteship';
@endphp

<div class="relative" data-area-search="{{ $id }}" data-multi="{{ $multi ? '1' : '0' }}">
    <div class="flex items-center justify-between mb-1 gap-2">
        <label class="block text-xs font-bold text-gray-500">{{ $label }}</label>
        @if($multi && count($providers) > 1)
            <select id="{{ $id }}_provider_sel" class="text-[11px] border border-gray-200 rounded px-1.5 py-0.5 bg-white text-gray-600">
                @foreach($providers as $code => $plabel)
                    <option value="{{ $code }}">{{ $plabel }}</option>
                @endforeach
            </select>
        @endif
    </div>

    <input type="text" id="{{ $id }}_search" autocomplete="off"
           value="{{ $text }}" placeholder="{{ $placeholder }}"
           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">

    @if($multi)
        {{-- Hidden per-provider (di-submit ke form). --}}
        @foreach($providers as $code => $plabel)
            <input type="hidden" id="{{ $id }}_{{ $code }}_id"
                   name="{{ $providerNames[$code] ?? ($code . '_area_id') }}"
                   value="{{ $providerValues[$code] ?? '' }}">
        @endforeach
        {{-- Provider terpilih + cermin id aktif (untuk JS lama). --}}
        <input type="hidden" id="{{ $id }}_provider" @if($providerField) name="{{ $providerField }}" @endif value="{{ $firstProvider }}">
        <input type="hidden" id="{{ $id }}_id" value="{{ $providerValues[$firstProvider] ?? '' }}">
    @else
        <input type="hidden" name="{{ $hiddenName }}" id="{{ $id }}_id" value="{{ $value }}" @if($required) data-required="1" @endif>
    @endif

    <div id="{{ $id }}_results"
         class="absolute z-30 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto hidden text-sm"></div>
    <p id="{{ $id }}_hint" class="text-[10px] text-gray-400 mt-1"></p>
</div>

<script>
(function () {
    const base    = @json($id);
    const url     = @json($url);
    const postalT = @json($postalTargetId);
    const provT   = @json($provinceTargetId);
    const cityT   = @json($cityTargetId);
    const distT   = @json($districtTargetId);
    const multi   = @json($multi);
    const firstProvider = @json($firstProvider);
    function setTarget(tid, val){ if (tid){ const el = document.getElementById(tid); if (el && val != null) el.value = val; } }
    const search  = document.getElementById(base + '_search');
    const hidden  = document.getElementById(base + '_id');           // cermin id aktif
    const provSel = document.getElementById(base + '_provider_sel'); // bisa null (1 provider)
    const provHid = document.getElementById(base + '_provider');     // ada saat multi
    const box     = document.getElementById(base + '_results');
    const hint    = document.getElementById(base + '_hint');
    if (!search) return;

    function currentProvider(){ return provSel ? provSel.value : (provHid ? provHid.value : firstProvider); }
    function providerHidden(p){ return document.getElementById(base + '_' + p + '_id'); }

    let timer = null;
    let lastReq = 0;

    function hide() { box.classList.add('hidden'); box.innerHTML = ''; }

    function render(areas) {
        if (!areas.length) {
            box.innerHTML = '<div class="px-3 py-2 text-gray-400">Tidak ada hasil.</div>';
            box.classList.remove('hidden');
            return;
        }
        box.innerHTML = '';
        areas.forEach(function (a) {
            const row = document.createElement('div');
            row.className = 'px-3 py-2 cursor-pointer hover:bg-blue-50 border-b border-gray-50 last:border-0';
            row.innerHTML = '<div class="text-gray-800">' + escapeHtml(a.name) + '</div>'
                + (a.postal_code ? '<div class="text-[11px] text-gray-400">Kode pos: ' + escapeHtml(a.postal_code) + '</div>' : '');
            row.addEventListener('mousedown', function (e) {
                e.preventDefault();
                const p = currentProvider();
                if (multi) { const ph = providerHidden(p); if (ph) ph.value = a.id || ''; }
                if (hidden) hidden.value = a.id || '';
                search.value = a.name || '';
                hint.textContent = a.id ? ('Area terpilih ✓' + (multi ? ' (' + p + ')' : '') + (a.postal_code ? ' · ' + a.postal_code : '')) : '';
                hint.className = 'text-[10px] text-green-600 mt-1';
                if (a.postal_code) setTarget(postalT, a.postal_code);
                setTarget(provT, a.province);
                setTarget(cityT, a.city);
                setTarget(distT, a.district);
                hide();
            });
            box.appendChild(row);
        });
        box.classList.remove('hidden');
    }

    function fetchAreas(q) {
        const reqId = ++lastReq;
        hint.textContent = 'Mencari…';
        hint.className = 'text-[10px] text-gray-400 mt-1';
        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        const qs  = (multi ? ('provider=' + encodeURIComponent(currentProvider()) + '&') : '') + 'input=' + encodeURIComponent(q);
        fetch(url + sep + qs, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (reqId !== lastReq) return; // balapan request — abaikan yang lama
                if (d.success === false && d.error) {
                    hint.textContent = d.error;
                    hint.className = 'text-[10px] text-red-500 mt-1';
                    hide();
                    return;
                }
                hint.textContent = '';
                render(d.areas || []);
            })
            .catch(function () {
                if (reqId !== lastReq) return;
                hint.textContent = 'Gagal memuat area.';
                hint.className = 'text-[10px] text-red-500 mt-1';
            });
    }

    search.addEventListener('input', function () {
        const p = currentProvider();
        if (multi) { const ph = providerHidden(p); if (ph) ph.value = ''; }
        if (hidden) hidden.value = ''; // teks berubah → wajib pilih ulang dari daftar
        hint.textContent = '';
        const q = search.value.trim();
        clearTimeout(timer);
        if (q.length < 3) { hide(); return; }
        timer = setTimeout(function () { fetchAreas(q); }, 350);
    });

    // Ganti provider → sinkronkan cermin id + provider terpilih, reset teks/daftar.
    if (provSel) {
        provSel.addEventListener('change', function () {
            const p = provSel.value;
            if (provHid) provHid.value = p;
            const ph = providerHidden(p);
            if (hidden) hidden.value = ph ? ph.value : '';
            search.value = '';
            hint.textContent = 'Provider: ' + p + ' — ketik untuk cari area.';
            hint.className = 'text-[10px] text-gray-400 mt-1';
            hide();
        });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-area-search="' + base + '"]')) hide();
    });

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
})();
</script>
