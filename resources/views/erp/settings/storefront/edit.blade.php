@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Etalase Website — API Jembatan</h2>
        <p class="text-sm text-gray-500 mb-6">
            Kunci rahasia yang dipakai server etalase (VPS / Cloudflare) untuk membaca
            katalog, stok live, &amp; promosi dari ERP lewat <span class="font-mono">/api/storefront/*</span>.
            Hanya server-side yang boleh memegang kunci ini — jangan taruh di kode frontend publik.
        </p>

        <form method="POST" action="{{ route('settings.storefront.update') }}" class="space-y-5">
            @csrf

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $setting->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300">
                Aktifkan API etalase
            </label>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Kunci API</label>
                <div class="flex gap-2">
                    <input type="text" readonly value="{{ $setting->api_key }}" id="sfKey"
                           class="w-full border rounded px-3 py-2 font-mono text-sm bg-gray-50"
                           placeholder="(belum dibuat — simpan untuk membuat otomatis)">
                    <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('sfKey').value)"
                            class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded text-sm whitespace-nowrap">Salin</button>
                </div>
                <p class="text-xs text-gray-400 mt-1">Disimpan saat pertama kali menyimpan pengaturan. Gunakan tombol di bawah untuk membuat ulang bila bocor.</p>
            </div>

            <div class="pt-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        @if($setting->api_key)
        <form method="POST" action="{{ route('settings.storefront.generate') }}" class="mt-3"
              onsubmit="return confirm('Buat ulang kunci API? Kunci lama langsung tidak berlaku — etalase harus diperbarui.')">
            @csrf
            <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-sm font-semibold">Buat Ulang Kunci</button>
        </form>
        @endif

        <div class="mt-6 pt-4 border-t border-gray-100 text-xs text-gray-500 leading-relaxed">
            <b>Endpoint baca (kirim header <span class="font-mono">Authorization: Bearer &lt;kunci&gt;</span>):</b>
            <ul class="mt-2 space-y-1 font-mono">
                <li>GET /api/storefront/categories</li>
                <li>GET /api/storefront/products <span class="text-gray-400">?updated_since=&amp;page=</span></li>
                <li>GET /api/storefront/products/&#123;slug&#125;</li>
                <li>GET /api/storefront/stock <span class="text-gray-400">?product_ids=1,2,3</span> <span class="text-gray-400">(stok LIVE)</span></li>
                <li>GET /api/storefront/promotions</li>
            </ul>
            <p class="mt-2">Katalog/harga boleh di-cache di etalase; <b>stok wajib live</b> (panggil /stock saat tampil &amp; checkout).</p>
        </div>
    </div>
</div>
@endsection
