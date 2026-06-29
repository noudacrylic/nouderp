@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Cloudflare R2 — Penyimpanan Media Etalase</h2>
        <p class="text-sm text-gray-500 mb-6">
            Object storage untuk <b>foto &amp; video Produk Store</b> (etalase web). Disajikan cepat via CDN, egress gratis.
            Buat bucket &amp; API token di <b>dash.cloudflare.com → R2</b>, lalu isi kredensial di sini.
            Saat <b>nonaktif</b>, media disimpan di disk lokal server (cocok untuk uji coba).
        </p>

        <form method="POST" action="{{ route('settings.r2.update') }}" class="space-y-5">
            @csrf

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $setting->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300">
                Aktifkan R2 (gunakan untuk menyimpan media)
            </label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Access Key ID</label>
                    <input type="text" name="access_key_id" value="{{ old('access_key_id', $setting->access_key_id) }}"
                           class="w-full border rounded px-3 py-2 font-mono text-sm" autocomplete="off">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Secret Access Key</label>
                    <input type="password" name="secret_access_key" value=""
                           class="w-full border rounded px-3 py-2 font-mono text-sm" autocomplete="new-password"
                           placeholder="{{ $setting->secret_access_key ? '•••••• (tersimpan — isi untuk mengganti)' : 'masukkan secret' }}">
                    <p class="text-xs text-gray-400 mt-1">Disimpan terenkripsi. Kosongkan untuk mempertahankan yang lama.</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Bucket</label>
                <input type="text" name="bucket" value="{{ old('bucket', $setting->bucket) }}"
                       class="w-full md:w-80 border rounded px-3 py-2 text-sm" placeholder="mis. noud-etalase">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Endpoint S3</label>
                <input type="text" name="endpoint" value="{{ old('endpoint', $setting->endpoint) }}"
                       class="w-full border rounded px-3 py-2 font-mono text-sm"
                       placeholder="https://<account_id>.r2.cloudflarestorage.com">
                <p class="text-xs text-gray-400 mt-1">Dari R2 → bucket → Settings → <i>S3 API</i>.</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">URL Publik</label>
                <input type="text" name="public_url" value="{{ old('public_url', $setting->public_url) }}"
                       class="w-full border rounded px-3 py-2 font-mono text-sm"
                       placeholder="https://media.noudakrilik.com  atau  https://pub-xxxx.r2.dev">
                <p class="text-xs text-gray-400 mt-1">Domain publik bucket (custom domain disarankan, atau r2.dev). Dipakai sebagai alamat file.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Region</label>
                    <input type="text" name="region" value="{{ old('region', $setting->region ?: 'auto') }}"
                           class="w-full border rounded px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">R2 biasanya <span class="font-mono">auto</span>.</p>
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="use_path_style" value="1" {{ old('use_path_style', $setting->use_path_style) ? 'checked' : '' }}
                               class="rounded border-gray-300">
                        Gunakan path-style endpoint
                    </label>
                </div>
            </div>

            <div class="pt-2 flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        <form method="POST" action="{{ route('settings.r2.test') }}" class="mt-3">
            @csrf
            <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-sm font-semibold">Uji Koneksi</button>
        </form>

        <div class="mt-6 pt-4 border-t border-gray-100 text-xs text-gray-500 leading-relaxed">
            <b>Catatan go-live:</b> driver S3 perlu dipasang sekali di server —
            <span class="font-mono">composer require league/flysystem-aws-s3-v3</span>. Setelah itu cukup isi &amp; aktifkan di sini,
            tanpa edit <span class="font-mono">.env</span>. File lama yang diganti dibersihkan otomatis oleh penjadwal
            <span class="font-mono">store:gc-media</span>.
        </div>
    </div>
</div>
@endsection
