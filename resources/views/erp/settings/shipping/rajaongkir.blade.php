@extends('layouts.erp')

@section('content')
<div class="max-w-2xl mx-auto py-4">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-lg font-semibold">Integrasi — RajaOngkir</h1>
            <p class="text-xs text-gray-500 mt-0.5">Cek ongkir (JNE, J&T, SiCepat, AnterAja, POS, TIKI, dll). Ramah perorangan, tanpa badan usaha.</p>
        </div>
        <span class="px-2 py-0.5 rounded text-xs font-bold uppercase {{ $setting->isConfigured() ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' }}">
            {{ $setting->isConfigured() ? 'Aktif' : 'Nonaktif' }}
        </span>
    </div>

    <div class="bg-blue-50 border border-blue-200 text-blue-800 px-3 py-2 rounded mb-4 text-sm">
        Daftar di <a href="https://rajaongkir.com" target="_blank" class="font-bold underline">rajaongkir.com</a> → dashboard <b>Developer → Settings → API Key</b> → ambil key <b>"Shipping Cost"</b>, tempel di bawah.
        Plan <b>Starter gratis</b> = 100 cek/hari (hasil di-cache 6 jam). <b>Buat resi/booking manual</b> (butuh plan Enterprise untuk otomatis).
    </div>

    <form action="{{ route('settings.shipping.rajaongkir.update') }}" method="POST" id="roForm" class="bg-white rounded shadow p-4 space-y-4">
        @csrf

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_enabled" value="1" {{ $setting->is_enabled ? 'checked' : '' }} class="accent-blue-600">
            <span class="font-semibold">Aktifkan RajaOngkir</span>
        </label>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">API Key (Shipping Cost)</label>
            <input type="text" name="api_key" value="{{ old('api_key', $setting->api_key) }}" autocomplete="off"
                   placeholder="key Shipping Cost dari dashboard RajaOngkir"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
            <p class="text-[10px] text-gray-400 mt-1">Disimpan di <code>shipping_settings</code>. Jangan dibagikan.</p>
        </div>

        {{-- Alamat asal (origin) kini diatur di Gudang penjualan, bukan di sini. --}}
        <div class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-xs text-gray-600">
            <b>Alamat asal ongkir</b> diambil dari <b>Gudang penjualan default</b>.
            @php $wo = \App\Core\Inventory\Warehouse::shippingOrigin(); @endphp
            @if($wo && $wo->rajaongkir_origin_id)
                Saat ini: <span class="font-medium text-gray-800">{{ $wo->rajaongkir_origin_label ?: ('ID ' . $wo->rajaongkir_origin_id) }}</span>
                (gudang {{ $wo->name }}).
                <a href="{{ route('inventory.warehouses.edit', $wo->id) }}" class="text-blue-600 hover:underline">Ubah di Gudang →</a>
            @elseif($wo)
                <span class="text-amber-600">Belum diatur.</span>
                Set <b>Area RajaOngkir</b> di
                <a href="{{ route('inventory.warehouses.edit', $wo->id) }}" class="text-blue-600 hover:underline">gudang {{ $wo->name }} →</a>
            @else
                <span class="text-amber-600">Belum ada gudang penjualan aktif.</span> Buat gudang (tandai "Bisa dijual") lalu isi Area RajaOngkir-nya.
            @endif
        </div>

        {{-- Kurir yang ditampilkan. --}}
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-2">Kurir yang Ditampilkan</label>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach($couriers as $code => $label)
                    <label class="flex items-center gap-2 text-sm border border-gray-100 rounded px-2 py-1.5 hover:bg-gray-50">
                        <input type="checkbox" name="couriers[]" value="{{ $code }}"
                               {{ in_array($code, $selectedCouriers, true) ? 'checked' : '' }} class="accent-blue-600">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            <p class="text-[10px] text-gray-400 mt-1">Kosong = pakai default. Kurir kargo/instan (J&T Cargo, GoSend) tidak tersedia di sini → tetap manual/WhatsApp.</p>
            <p class="text-[10px] text-amber-600 mt-1">⚠️ <b>AnterAja</b> dikembalikan RajaOngkir dengan tarif <b>flat ± Rp11.800 yang tidak mengikuti berat</b> (diuji 1/5/15 kg hasilnya sama). Untuk paket berat ongkirnya jauh di bawah biaya sebenarnya — sebaiknya biarkan tidak dicentang.</p>
        </div>

        <div class="flex justify-end pt-2 border-t">
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Simpan</button>
        </div>
    </form>
</div>
@endsection
