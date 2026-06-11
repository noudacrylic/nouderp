@php
    $w = $warehouse ?? null;
    // Pemilih provider area: tampil hanya bila KiriminAja diaktifkan di Settings.
    $kaOn = \App\Models\ShippingSetting::for('kiriminaja')->is_enabled;
    $btOn = \App\Models\ShippingSetting::for('biteship')->is_enabled;
    $areaProviders = [];
    if ($kaOn) {
        if ($btOn) $areaProviders['biteship'] = 'Biteship';
        $areaProviders['kiriminaja'] = 'KiriminAja';
    }
@endphp

<div class="space-y-3">
    <div>
        <label class="block text-xs font-bold text-gray-500 mb-1">Alamat Lengkap</label>
        <input type="text" name="address" value="{{ old('address', $w?->address ?? '') }}"
               placeholder="Jl. Contoh No.1, RT/RW, Kelurahan"
               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Kota / Kab.</label>
            <input type="text" name="city" value="{{ old('city', $w?->city ?? '') }}"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Provinsi</label>
            <input type="text" name="province" value="{{ old('province', $w?->province ?? '') }}"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>
    </div>

    @include('partials.area-search', [
        'id'             => 'wh_area',
        'url'            => route('inventory.warehouses.areas'),
        'hiddenName'     => 'biteship_area_id',
        'label'          => empty($areaProviders) ? 'Area Biteship (untuk ongkir akurat)' : 'Area Kurir (untuk ongkir akurat)',
        'value'          => old('biteship_area_id', $w?->biteship_area_id ?? ''),
        'text'           => ($w?->biteship_area_id || $w?->kiriminaja_area_id) ? $w->fullAddress() : '',
        'placeholder'    => 'Ketik kelurahan / kecamatan untuk cari area…',
        'postalTargetId' => 'wh_postal_code',
        'providers'      => $areaProviders,
        'providerNames'  => ['biteship' => 'biteship_area_id', 'kiriminaja' => 'kiriminaja_area_id'],
        'providerValues' => [
            'biteship'   => old('biteship_area_id', $w?->biteship_area_id ?? ''),
            'kiriminaja' => old('kiriminaja_area_id', $w?->kiriminaja_area_id ?? ''),
        ],
    ])

    <div class="grid grid-cols-3 gap-3">
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Kode Pos</label>
            <input type="text" name="postal_code" id="wh_postal_code" value="{{ old('postal_code', $w?->postal_code ?? '') }}"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Nama Kontak</label>
            <input type="text" name="contact_name" value="{{ old('contact_name', $w?->contact_name ?? '') }}"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">No. HP Kontak</label>
            <input type="text" name="contact_phone" value="{{ old('contact_phone', $w?->contact_phone ?? '') }}"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>
    </div>

    <div>
        <label class="block text-xs font-bold text-gray-500 mb-1">Titik Lokasi <span class="text-gray-400 font-normal">(untuk kurir instant — opsional)</span></label>
        @php $whPoint = old('location_point', ($w && $w->latitude !== null && $w->longitude !== null) ? ($w->latitude . ',' . $w->longitude) : ''); @endphp
        <input type="text" name="location_point" value="{{ $whPoint }}"
               placeholder="Paste link Google Maps atau 'lat,long' (mis. -7.79,110.36)"
               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        <p class="text-[11px] text-gray-400 mt-1">Wajib jika gudang ini jadi asal pengiriman <b>kurir instant</b> (Grab/GoSend/Lalamove). Google Maps → klik titik → Share → paste link.</p>
    </div>
</div>
