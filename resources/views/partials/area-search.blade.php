@php
    /**
     * Autocomplete area Biteship → area_id.
     *
     * Param:
     *   $id              string  basis id DOM unik (wajib)
     *   $url             string  endpoint pencarian area (wajib)
     *   $hiddenName      string  name input tersembunyi penyimpan area_id (wajib)
     *   $label           string  label field
     *   $value           ?string area_id terpilih saat ini
     *   $text            ?string teks tampilan area terpilih saat ini
     *   $placeholder     ?string
     *   $required        ?bool
     *   $postalTargetId  ?string id input kode pos yang ikut terisi saat memilih area
     *   $provinceTargetId/$cityTargetId/$districtTargetId ?string id input yang ikut terisi
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
@endphp

<div class="relative" data-area-search="{{ $id }}">
    <label class="block text-xs font-bold text-gray-500 mb-1">{{ $label }}</label>
    <input type="text" id="{{ $id }}_search" autocomplete="off"
           value="{{ $text }}" placeholder="{{ $placeholder }}"
           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
    <input type="hidden" name="{{ $hiddenName }}" id="{{ $id }}_id" value="{{ $value }}" @if($required) data-required="1" @endif>

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
    function setTarget(tid, val){ if (tid){ const el = document.getElementById(tid); if (el && val != null) el.value = val; } }
    const search  = document.getElementById(base + '_search');
    const hidden  = document.getElementById(base + '_id');
    const box     = document.getElementById(base + '_results');
    const hint    = document.getElementById(base + '_hint');
    if (!search) return;

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
                hidden.value = a.id || '';
                search.value = a.name || '';
                hint.textContent = a.id ? ('Area terpilih ✓' + (a.postal_code ? ' · ' + a.postal_code : '')) : '';
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
        fetch(url + '?input=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
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
        hidden.value = ''; // teks berubah → wajib pilih ulang dari daftar
        hint.textContent = '';
        const q = search.value.trim();
        clearTimeout(timer);
        if (q.length < 3) { hide(); return; }
        timer = setTimeout(function () { fetchAreas(q); }, 350);
    });

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
