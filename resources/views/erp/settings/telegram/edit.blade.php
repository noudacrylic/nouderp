@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Telegram — Noud Bot</h2>
        <p class="text-sm text-gray-500 mb-6">
            Notifikasi keluar via Telegram: pengajuan izin baru ke approver, dan status approval ke karyawan.
            Buat bot di <b>&#64;BotFather</b> (perintah <span class="font-mono">/newbot</span>), lalu salin <i>token</i> &amp; <i>username</i>-nya ke sini.
        </p>

        {{-- ===== Konfigurasi bot ===== --}}
        <form method="POST" action="{{ route('settings.telegram.update') }}" class="space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Bot Token</label>
                <input type="text" name="bot_token" value="{{ old('bot_token', $setting->bot_token) }}"
                       class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                <p class="text-xs text-gray-400 mt-1">Dari &#64;BotFather setelah <span class="font-mono">/newbot</span>.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Bot Username</label>
                    <div class="flex items-center">
                        <span class="px-3 py-2 bg-gray-100 border border-r-0 rounded-l text-gray-500 text-sm">&#64;</span>
                        <input type="text" name="bot_username" value="{{ old('bot_username', $setting->usernameClean()) }}"
                               class="w-full border rounded-r px-3 py-2 font-mono text-sm" placeholder="noud_erp_bot">
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Tanpa &#64;. Dipakai untuk tautan hubungkan akun.</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Admin/Grup Chat ID <span class="text-gray-400 font-normal">(opsional)</span></label>
                    <input type="text" name="admin_chat_id" value="{{ old('admin_chat_id', $setting->admin_chat_id) }}"
                           class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="-1001234567890">
                    <p class="text-xs text-gray-400 mt-1">Penampung notifikasi approval bila approver belum hubungkan akun pribadi.</p>
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $setting->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300">
                Aktifkan notifikasi Telegram
            </label>

            <div class="pt-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        @if($botInfo)
            <div class="mt-4 text-xs rounded-lg px-3 py-2 {{ data_get($botInfo,'ok') ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' }}">
                @if(data_get($botInfo,'ok'))
                    ✅ Token valid — bot <b>&#64;{{ data_get($botInfo,'result.username') }}</b> ({{ data_get($botInfo,'result.first_name') }}).
                @else
                    ⚠️ Token tidak valid: {{ data_get($botInfo,'description','gagal terhubung') }}
                @endif
            </div>
        @endif

        {{-- ===== Webhook ===== --}}
        <hr class="my-6">
        <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Webhook</h3>

        @if($webhookUrl)
            <div class="bg-gray-50 border rounded-lg p-3 mb-3">
                <p class="text-xs text-gray-500 mb-1">URL webhook (otomatis ikut domain aplikasi):</p>
                <input type="text" readonly value="{{ $webhookUrl }}"
                       class="w-full border rounded px-3 py-2 font-mono text-xs bg-white" onclick="this.select()">
            </div>
            @if($webhookInfo)
                <div class="text-xs text-gray-600 mb-3 space-y-0.5">
                    <div>Status: <b>{{ data_get($webhookInfo,'result.url') ? 'Terpasang' : 'Belum terpasang' }}</b></div>
                    @if(data_get($webhookInfo,'result.last_error_message'))
                        <div class="text-red-600">Error terakhir: {{ data_get($webhookInfo,'result.last_error_message') }}</div>
                    @endif
                    <div>Antrean tertunda: {{ data_get($webhookInfo,'result.pending_update_count', 0) }}</div>
                </div>
            @endif
        @else
            <p class="text-xs text-gray-500 mb-3">Simpan Bot Token dulu untuk membuat URL webhook.</p>
        @endif

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('settings.telegram.set-webhook') }}">
                @csrf
                <button type="submit" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded text-sm font-semibold">Pasang / Refresh Webhook</button>
            </form>
            <form method="POST" action="{{ route('settings.telegram.delete-webhook') }}"
                  onsubmit="return confirm('Lepas webhook? Bot berhenti menerima pesan sampai dipasang lagi.')">
                @csrf
                <button type="submit" class="px-3 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 rounded text-sm font-semibold">Lepas Webhook</button>
            </form>
        </div>
        <p class="text-xs text-gray-400 mt-2">Webhook butuh aplikasi dapat diakses publik via HTTPS (mis. Cloudflare Tunnel). Domain saat ini: <span class="font-mono">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</span>.</p>

        {{-- ===== Hubungkan akun saya ===== --}}
        <hr class="my-6">
        <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Akun Telegram Saya</h3>

        @if($user->telegram_chat_id)
            <div class="flex items-center justify-between gap-3 bg-green-50 border border-green-200 rounded-lg p-3">
                <div class="text-sm text-green-700">✅ Terhubung — chat ID <span class="font-mono">{{ $user->telegram_chat_id }}</span>.</div>
                <form method="POST" action="{{ route('settings.telegram.unlink') }}"
                      onsubmit="return confirm('Putuskan akun Telegram Anda?')">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 rounded text-sm font-semibold">Putuskan</button>
                </form>
            </div>
        @else
            <div class="flex items-center justify-between gap-3 bg-gray-50 border rounded-lg p-3">
                <div class="text-sm text-gray-600">Belum terhubung. Tekan tombol untuk membuka bot &amp; tekan <b>Start</b>.</div>
                <form method="POST" action="{{ route('settings.telegram.link') }}">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white rounded text-sm font-semibold whitespace-nowrap">Hubungkan</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
