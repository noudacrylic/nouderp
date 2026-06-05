@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto py-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Cek Ongkir</h1>
        <a href="{{ route('settings.shipping.biteship') }}" class="text-xs text-blue-600 hover:underline">⚙️ Setting Biteship</a>
    </div>

    @php $biteship = \App\Models\ShippingSetting::for('biteship'); @endphp
    @if(!$biteship->isConfigured())
        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 rounded mb-4 text-sm">
            Biteship belum aktif / API key kosong. Isi dulu di
            <a href="{{ route('settings.shipping.biteship') }}" class="font-bold underline">Settings → Biteship</a> agar ongkir bisa diambil.
        </div>
    @endif

    <form action="{{ route('sales.cek-ongkir.check') }}" method="POST" class="bg-white rounded shadow p-4 mb-4" id="ongkir-form">
        @csrf
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
                    <a href="{{ route('inventory.warehouses.index') }}" class="underline font-semibold">Lengkapi di Warehouse</a>.
                </p>
            </div>

            {{-- TUJUAN: cari alamat --}}
            <div>
                @include('partials.area-search', [
                    'id'          => 'dest_area',
                    'url'         => route('sales.cek-ongkir.areas'),
                    'hiddenName'  => 'destination_area_id',
                    'label'       => 'Alamat Tujuan',
                    'value'       => $input['destination_area_id'] ?? '',
                    'text'        => $input['destination_label'] ?? '',
                    'placeholder' => 'Ketik kelurahan / kecamatan / kota tujuan…',
                    'required'    => true,
                ])
                <input type="hidden" name="destination_label" id="dest_label" value="{{ $input['destination_label'] ?? '' }}">
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
        </div>
        <div class="flex justify-end mt-3">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-bold">🔍 Cek Ongkir</button>
        </div>
    </form>

    @if(!empty($errors) && is_array($errors) && count($errors))
        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-4 text-sm">
            @foreach($errors as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    @if($rates !== null)
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Kurir</th>
                        <th class="px-3 py-2 text-left">Layanan</th>
                        <th class="px-3 py-2 text-left">Estimasi</th>
                        <th class="px-3 py-2 text-right">Ongkir</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rates as $r)
                        <tr class="border-b hover:bg-blue-50">
                            <td class="px-3 py-2 font-semibold uppercase">{{ $r['courier_code'] }}</td>
                            <td class="px-3 py-2">{{ $r['service_name'] ?: $r['service_code'] }}</td>
                            <td class="px-3 py-2 text-gray-500">{{ $r['etd'] }}</td>
                            <td class="px-3 py-2 text-right font-bold text-gray-800">Rp {{ number_format($r['price'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">Tidak ada layanan ongkir ditemukan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
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
})();
</script>
@endsection
