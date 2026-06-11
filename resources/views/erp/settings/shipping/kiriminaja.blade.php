@extends('layouts.erp')

@section('content')
<div class="max-w-2xl mx-auto py-4">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-lg font-semibold">Integrasi — KiriminAja</h1>
            <p class="text-xs text-gray-500 mt-0.5">Aggregator kurir reguler & instant (GoSend/Grab) untuk cek ongkir & booking resi.</p>
        </div>
        <span class="px-2 py-0.5 rounded text-xs font-bold uppercase {{ $setting->isConfigured() ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' }}">
            {{ $setting->isConfigured() ? 'Aktif' : 'Nonaktif' }}
        </span>
    </div>


    <div class="bg-blue-50 border border-blue-200 text-blue-800 px-3 py-2 rounded mb-4 text-sm">
        Daftar di <a href="https://kiriminaja.com" target="_blank" class="font-bold underline">kiriminaja.com</a> → ambil <b>API Key</b> di dashboard developer, tempel di bawah. Auth pakai <b>Bearer token</b>.
        Base URL otomatis: <code>tdev.kiriminaja.com</code> (sandbox) / <code>client.kiriminaja.com</code> (production).
    </div>

    @if($balance !== null)
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-2 rounded mb-4 text-sm">
            Saldo KiriminAja (live): <b>Rp {{ number_format($balance, 0, ',', '.') }}</b>
        </div>
    @endif

    <form action="{{ route('settings.shipping.kiriminaja.update') }}" method="POST" class="bg-white rounded shadow p-4 space-y-4">
        @csrf

        <div class="flex items-center gap-6">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_enabled" value="1" {{ $setting->is_enabled ? 'checked' : '' }} class="accent-blue-600">
                <span class="font-semibold">Aktifkan KiriminAja</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_production" value="1" {{ $setting->is_production ? 'checked' : '' }} class="accent-blue-600">
                <span>Mode Production <span class="text-gray-400">(kosong = sandbox/tdev)</span></span>
            </label>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">API Key</label>
            <input type="text" name="api_key" value="{{ old('api_key', $setting->api_key) }}" autocomplete="off"
                   placeholder="token KiriminAja"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
            <p class="text-[10px] text-gray-400 mt-1">Disimpan di tabel <code>shipping_settings</code>. Jangan dibagikan.</p>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Base URL <span class="text-gray-400">(opsional — kosongkan untuk auto sandbox/prod)</span></label>
            <input type="text" name="base_url" value="{{ old('base_url', $setting->base_url) }}"
                   placeholder="biarkan kosong = otomatis"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Webhook Token <span class="text-gray-400">(opsional, untuk tracking nanti)</span></label>
            <input type="text" name="webhook_token" value="{{ old('webhook_token', $setting->webhook_token) }}" autocomplete="off"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        <div class="pt-3 border-t border-gray-100" id="courier-picker">
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs font-bold text-gray-500">Kurir Reguler yang Dipakai</label>
                <div class="flex gap-2">
                    <button type="button" data-courier-all class="px-2 py-0.5 rounded text-[11px] border border-gray-300 text-gray-600 hover:bg-gray-50">Pilih semua</button>
                    <button type="button" data-courier-none class="px-2 py-0.5 rounded text-[11px] border border-gray-300 text-gray-600 hover:bg-gray-50">Hapus semua</button>
                </div>
            </div>
            <p class="text-[10px] text-gray-400 mb-2">Centang kurir yang muncul saat cek ongkir & booking. Kosongkan = pakai semua kurir.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                @foreach($couriers as $code => $name)
                    <label class="flex items-center gap-2 min-w-0 text-sm border-b border-gray-50 pb-1">
                        <input type="checkbox" name="couriers[]" value="{{ $code }}" class="accent-blue-600 courier-cb"
                               {{ in_array($code, old('couriers', $selectedCouriers), true) ? 'checked' : '' }}>
                        <span class="truncate">{{ $name }} <span class="text-gray-400 text-[11px]">({{ $code }})</span></span>
                    </label>
                @endforeach
            </div>
            <p class="text-[10px] text-gray-400 mt-3">Instant tersedia: @foreach($instantServices as $c => $n)<span class="inline-block bg-gray-100 rounded px-1.5 py-0.5 mr-1">{{ $n }}</span>@endforeach <span class="text-gray-400">(dipilih saat cek ongkir mode instant).</span></p>
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-gray-100">
            <a href="{{ route('sales.cek-ongkir') }}" class="px-4 py-2 rounded text-sm border border-gray-200 text-gray-600 hover:bg-gray-50">Coba Cek Ongkir →</a>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-bold">Simpan</button>
        </div>
    </form>

    <script>
    (function () {
        const wrap = document.getElementById('courier-picker');
        if (!wrap) return;
        const boxes = wrap.querySelectorAll('.courier-cb');
        wrap.querySelector('[data-courier-all]')?.addEventListener('click', () => boxes.forEach(b => b.checked = true));
        wrap.querySelector('[data-courier-none]')?.addEventListener('click', () => boxes.forEach(b => b.checked = false));
    })();
    </script>
</div>
@endsection
