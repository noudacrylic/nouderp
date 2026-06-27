@extends('layouts.erp')

@section('content')
@php
    $textModels = [
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6 — seimbang (disarankan untuk teks)',
        'claude-opus-4-8'   => 'Claude Opus 4.8 — paling teliti (lebih mahal)',
        'claude-haiku-4-5'  => 'Claude Haiku 4.5 — paling murah',
    ];
    $visionModels = [
        'claude-opus-4-8'   => 'Claude Opus 4.8 — terbaik baca struk',
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6 — lebih murah',
    ];
@endphp
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Claude AI — Asisten Pencatat Keuangan</h2>
        <p class="text-sm text-gray-500 mb-6">
            Otak AI untuk asisten Telegram (mencatat pengeluaran &amp; prive lewat chat).
            Buat API key di <b>console.anthropic.com</b> → <i>API Keys</i>, lalu tempel di sini.
            Asisten juga butuh <a href="{{ route('settings.telegram.edit') }}" class="text-blue-600 hover:underline">Telegram</a> aktif &amp; akun owner ter-link.
        </p>

        <form method="POST" action="{{ route('settings.anthropic.update') }}" class="space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">API Key</label>
                <input type="text" name="api_key" value="{{ old('api_key', $setting->api_key) }}"
                       class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="sk-ant-api03-...">
                <p class="text-xs text-gray-400 mt-1">
                    Dari console.anthropic.com. Kosongkan untuk memakai key dari <span class="font-mono">.env</span>
                    @if($envKeySet)
                        <span class="text-green-600 font-semibold">(.env terisi ✓)</span>
                    @else
                        <span class="text-gray-400">(.env kosong)</span>
                    @endif
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Model — Perintah Teks</label>
                    <select name="model_text" class="w-full border rounded px-3 py-2 text-sm">
                        @foreach($textModels as $val => $label)
                            <option value="{{ $val }}" @selected(old('model_text', $setting->model_text) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Model — Baca Struk <span class="text-gray-400 font-normal">(nanti)</span></label>
                    <select name="model_vision" class="w-full border rounded px-3 py-2 text-sm">
                        @foreach($visionModels as $val => $label)
                            <option value="{{ $val }}" @selected(old('model_vision', $setting->model_vision) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Ambang Konfirmasi (Rp)</label>
                <input type="number" name="confirm_threshold" min="0" step="1000"
                       value="{{ old('confirm_threshold', $setting->confirm_threshold) }}"
                       class="w-full md:w-64 border rounded px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">
                    Transaksi di atas nilai ini wajib dikonfirmasi (balas <b>ya</b>) sebelum diposting.
                    Isi <span class="font-mono">0</span> agar <b>semua</b> transaksi minta konfirmasi (aman untuk uji coba).
                </p>
            </div>

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $setting->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300">
                Aktifkan asisten AI
            </label>

            <div class="pt-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        <div class="mt-6 pt-4 border-t border-gray-100 text-xs text-gray-500 leading-relaxed">
            <b>Cara pakai (Telegram):</b> ketik perintah biasa, mis.
            <span class="font-mono">catat pengeluaran bensin 50rb dari kas</span> atau
            <span class="font-mono">prive 200rb dari BCA</span>.
            Perintah lain: <span class="font-mono">/batal</span> (batalkan transaksi terakhir),
            <span class="font-mono">/baru</span> (mulai ulang). Saat ini mendukung pengeluaran &amp; prive;
            transfer, pembelian, dan baca struk menyusul.
        </div>
    </div>
</div>
@endsection
