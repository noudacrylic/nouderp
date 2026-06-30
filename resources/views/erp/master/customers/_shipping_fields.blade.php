@php
    /**
     * Editor alamat pengiriman customer — kolom yang SAMA dengan popup "Edit/Tambah Alamat"
     * di form SO/Invoice (CustomerController::updateShipping). Dipakai di create & edit master.
     */
    $c = $customer ?? null;
    // Pemilih provider area: tampil hanya bila KiriminAja diaktifkan di Settings.
    $kaOn = \App\Models\ShippingSetting::for('kiriminaja')->is_enabled;
    $btOn = \App\Models\ShippingSetting::for('biteship')->is_enabled;
    $areaProviders = [];
    if ($kaOn) {
        if ($btOn) $areaProviders['biteship'] = 'Biteship';
        $areaProviders['kiriminaja'] = 'KiriminAja';
    }
    $custPoint = old('location_point', ($c && $c->latitude !== null && $c->longitude !== null) ? ($c->latitude . ',' . $c->longitude) : '');
@endphp

<div class="col-span-2 border-t pt-5 mt-1">
    <h2 class="text-sm font-bold text-gray-700 mb-1">Alamat Pengiriman</h2>
    <p class="text-xs text-gray-400 mb-3">Alamat ini dipakai di nota (Penawaran/SO/Faktur) & perhitungan ongkir. Sama dengan popup "Edit/Tambah Alamat" di form Sales Order.</p>
</div>

<div>
    <label class="block text-sm mb-1">No. HP Penerima</label>
    <input type="text" name="recipient_phone" value="{{ old('recipient_phone', $c?->recipient_phone ?? '') }}"
        class="border rounded px-3 py-2 w-full">
</div>

<div>
    <label class="block text-sm mb-1">Kode Pos</label>
    <input type="text" name="postal_code" id="cust_postal" value="{{ old('postal_code', $c?->postal_code ?? '') }}"
        class="border rounded px-3 py-2 w-full">
</div>

<div class="col-span-2">
    @include('partials.area-search', [
        'id'               => 'cust_area',
        'url'              => url('/erp/api/shipping/areas'),
        'hiddenName'       => 'biteship_area_id',
        'label'            => empty($areaProviders) ? 'Cari Area (Kelurahan / Kecamatan / Kota)' : 'Cari Area Kurir (Kelurahan / Kecamatan / Kota)',
        'value'            => old('biteship_area_id', $c?->biteship_area_id ?? ''),
        'text'             => ($c && ($c->biteship_area_id || $c->kiriminaja_area_id)) ? trim(collect([$c->district, $c->city, $c->province])->filter()->implode(', ')) : '',
        'placeholder'      => 'Ketik kelurahan / kecamatan / kota tujuan…',
        'postalTargetId'   => 'cust_postal',
        'provinceTargetId' => 'cust_province',
        'cityTargetId'     => 'cust_city',
        'districtTargetId' => 'cust_district',
        'providers'        => $areaProviders,
        'providerNames'    => ['biteship' => 'biteship_area_id', 'kiriminaja' => 'kiriminaja_area_id'],
        'providerValues'   => [
            'biteship'   => old('biteship_area_id', $c?->biteship_area_id ?? ''),
            'kiriminaja' => old('kiriminaja_area_id', $c?->kiriminaja_area_id ?? ''),
        ],
    ])
    <p class="text-xs text-gray-400 mt-1">Provinsi, kota, kecamatan & kode pos terisi otomatis dari pilihan di atas.</p>
</div>

<div>
    <label class="block text-sm mb-1">Kecamatan</label>
    <input type="text" name="district" id="cust_district" value="{{ old('district', $c?->district ?? '') }}"
        class="border rounded px-3 py-2 w-full">
</div>

<div>
    <label class="block text-sm mb-1">Kota / Kab.</label>
    <input type="text" name="city" id="cust_city" value="{{ old('city', $c?->city ?? '') }}"
        class="border rounded px-3 py-2 w-full">
</div>

<div class="col-span-2">
    <label class="block text-sm mb-1">Provinsi</label>
    <input type="text" name="province" id="cust_province" value="{{ old('province', $c?->province ?? '') }}"
        class="border rounded px-3 py-2 w-full">
</div>

<div class="col-span-2">
    <label class="block text-sm mb-1">Detail Alamat (jalan, no. rumah, RT/RW, patokan)</label>
    <textarea name="shipping_address" rows="2"
        class="border rounded px-3 py-2 w-full" placeholder="Nama jalan, No. rumah, RT/RW, patokan…">{{ old('shipping_address', $c?->shipping_address ?? '') }}</textarea>
</div>

<div class="col-span-2">
    <label class="block text-sm mb-1">Titik Lokasi <span class="text-gray-400 font-normal">(untuk kurir instant — opsional)</span></label>
    <input type="text" name="location_point" value="{{ $custPoint }}"
        placeholder="Paste link Google Maps atau 'lat,long' (mis. -7.79,110.36)"
        class="border rounded px-3 py-2 w-full">
    <p class="text-xs text-gray-400 mt-1">Wajib jika mau pakai kurir <b>instant</b> (Grab/GoSend/Lalamove). Google Maps → klik titik → Share → paste link.</p>
</div>
