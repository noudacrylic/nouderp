@extends('layouts.erp')

@section('content')
<div class="max-w-2xl mx-auto py-4">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-lg font-semibold">Integrasi — Jubelio Shipment</h1>
            <p class="text-xs text-gray-500 mt-0.5">Agregator kurir Jubelio. Terbit resi <b>tanpa badan usaha</b>, termasuk layanan <b>CARGO</b> (J&amp;T Cargo dkk).</p>
        </div>
        <span class="px-2 py-0.5 rounded text-xs font-bold uppercase {{ $provider->isReady() ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' }}">
            {{ $provider->isReady() ? 'Aktif' : 'Nonaktif' }}
        </span>
    </div>

    <div class="bg-blue-50 border border-blue-200 text-blue-800 px-3 py-2 rounded mb-4 text-sm">
        Minta <b>Client ID</b> &amp; <b>Client Secret</b> ke PIC Jubelio setelah akun di-onboard ke Jubelio Shipment.
        Keduanya ditukar otomatis menjadi token berlaku 24 jam — token disimpan di cache, bukan diminta tiap panggilan.
        Base URL otomatis: <code>api-shipment.sandbox.jubelio.com</code> (sandbox) / <code>api-shipment.jubelio.com</code> (production).
    </div>

    @if($liveError)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 rounded mb-4 text-sm">
            Daftar kategori gagal diambil dari Jubelio: <b>{{ $liveError }}</b>. Yang ditampilkan di bawah adalah daftar bawaan kontrak v1.8.
        </div>
    @endif

    {{-- Uji koneksi dipisah dari form pengaturan supaya menekannya tidak ikut menyimpan
         kredensial yang mungkin sedang setengah diketik. --}}
    <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-sm font-bold text-slate-800 mb-1">Uji Koneksi</h3>
                <p class="text-xs text-slate-600">
                    Meminta token baru dengan kredensial tersimpan, lalu membaca daftar kategori layanan.
                    Cara tercepat memastikan Client ID/Secret benar sebelum dipakai transaksi.
                </p>
            </div>
            <form method="POST" action="{{ route('settings.shipping.jubelio-shipment.test') }}">
                @csrf
                <button class="border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 rounded text-sm font-semibold whitespace-nowrap transition">
                    Uji Sekarang
                </button>
            </form>
        </div>
    </div>

    <form action="{{ route('settings.shipping.jubelio-shipment.update') }}" method="POST" class="bg-white rounded shadow p-4 space-y-4">
        @csrf

        <div class="flex items-center gap-6">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', $setting->is_enabled) ? 'checked' : '' }} class="accent-blue-600">
                <span class="font-semibold">Aktifkan Jubelio Shipment</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_production" value="1" {{ old('is_production', $setting->is_production) ? 'checked' : '' }} class="accent-blue-600">
                <span>Mode Production <span class="text-gray-400">(kosong = sandbox)</span></span>
            </label>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Client ID</label>
            <input type="text" name="client_id" value="{{ old('client_id', $provider->clientId()) }}" autocomplete="off"
                   placeholder="dari PIC Jubelio Shipment"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Client Secret</label>
            <input type="text" name="api_key" value="{{ old('api_key', $setting->api_key) }}" autocomplete="off"
                   placeholder="dari PIC Jubelio Shipment"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
            <p class="text-[10px] text-gray-400 mt-1">Disimpan di tabel <code>shipping_settings</code>. Jangan dibagikan.</p>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Base URL <span class="text-gray-400">(opsional — kosongkan untuk auto sandbox/prod)</span></label>
            <input type="text" name="base_url" value="{{ old('base_url', $setting->base_url) }}"
                   placeholder="biarkan kosong = otomatis"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        {{-- ===== Kategori layanan ===== --}}
        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Kategori Layanan yang Ditawarkan</label>
            <p class="text-[11px] text-gray-500 mb-2">
                Hanya kategori tercentang yang muncul saat Cek Ongkir. Kosongkan semua = tampilkan apa pun yang dikembalikan Jubelio.
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach($categories as $id => $name)
                    <label class="flex items-center gap-2 text-sm border rounded px-2 py-1.5 {{ $name === 'CARGO' ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200' }}">
                        <input type="checkbox" name="categories[]" value="{{ $id }}"
                               {{ in_array((int) $id, $selectedCategories) ? 'checked' : '' }} class="accent-blue-600">
                        <span>{{ $name }}@if($name === 'CARGO') <span class="text-[10px] text-emerald-700 font-bold">← J&amp;T Cargo</span>@endif</span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- ===== Webhook ===== --}}
        <div class="border-t pt-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-2">Webhook Status Pengiriman</h3>
            <p class="text-[11px] text-gray-500 mb-2">
                Daftarkan URL ini di <b>Dashboard Jubelio Shipment → Setting → Developer → Webhook</b>, lalu salin
                <i>secret</i>-nya ke kolom di bawah. Tanpa secret yang cocok, semua kiriman status ditolak.
            </p>
            <div class="flex gap-2 mb-3">
                <input type="text" readonly value="{{ $webhookUrl }}" onclick="this.select()"
                       class="flex-1 border rounded px-3 py-2 font-mono text-xs bg-gray-50">
                <button type="button"
                        onclick="navigator.clipboard.writeText('{{ $webhookUrl }}'); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Copy',1500)"
                        class="px-3 py-2 bg-gray-700 hover:bg-gray-800 text-white rounded text-sm font-semibold whitespace-nowrap">Copy</button>
            </div>
            <label class="block text-xs font-bold text-gray-500 mb-1">Webhook Secret</label>
            <input type="text" name="webhook_token" value="{{ old('webhook_token', $setting->webhook_token) }}" autocomplete="off"
                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('settings.integrations.index') }}" class="px-4 py-2 text-sm text-gray-600 hover:underline">Batal</a>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        </div>
    </form>

    <p class="text-[11px] text-gray-400 mt-4">
        Sumber: <b>API Contract Jubelio Shipment v1.8</b>. Kurir Jubelio diidentifikasi dengan ID angka
        (<code>courier_id</code> + <code>courier_service_id</code>), bukan kode huruf seperti provider lain —
        nilai itu tersimpan otomatis saat memilih layanan di Cek Ongkir.
    </p>
</div>
@endsection
