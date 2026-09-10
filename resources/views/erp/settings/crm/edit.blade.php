@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">CRM WhatsApp — api.co.id</h2>
        <p class="text-sm text-gray-500 mb-5">
            Kredensial Chat Gateway, pengaman pengiriman, dan pemantau endpoint webhook.
            Kunci API dibuat di dasbor vendor (menu <b>API Keys</b>); URL webhook disetel di menu
            <b>Webhooks</b> mereka — dari sini hanya bisa dipantau &amp; diaktifkan ulang.
        </p>

        {{-- ===== Palang keadaan pengiriman ===== --}}
        @if($dryRun)
            <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <b>Mode jangan-kirim aktif.</b> Semua pesan tetap tercatat lengkap (outbox, thread),
                tapi tidak satu pun benar-benar terkirim ke pelanggan. Ini bawaan yang aman selama
                pembangunan &amp; uji coba.
            </div>
        @else
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <b>Pengiriman NYATA aktif.</b>
                @if(($nilai['allowed_recipients'] ?? '') === '')
                    Daftar putih penerima <b>kosong</b> — pesan bisa sampai ke nomor pelanggan mana pun.
                @else
                    Terbatas pada nomor di daftar putih penerima di bawah.
                @endif
            </div>
        @endif

        <form method="POST" action="{{ route('settings.crm.update') }}" class="space-y-6">
            @csrf

            {{-- ===== Kredensial ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Kredensial</h3>

                <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700 mb-4">
                    <input type="checkbox" name="is_enabled" value="1" {{ old('is_enabled', $setting->is_enabled) ? 'checked' : '' }}
                           class="rounded border-gray-300">
                    Aktifkan integrasi api.co.id
                </label>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">API Key</label>
                        <input type="password" name="api_key" autocomplete="new-password"
                               class="w-full border rounded px-3 py-2 font-mono text-sm"
                               placeholder="{{ $setting->api_key ? '•••••••• (tersimpan — kosongkan bila tidak diubah)' : 'Tempel API key dari dasbor api.co.id' }}">
                        <p class="text-xs text-gray-400 mt-1">Dikirim sebagai <span class="font-mono">Bearer</span>. Dibiarkan kosong = nilai lama dipertahankan.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Rahasia Webhook (HMAC)</label>
                        <input type="password" name="webhook_secret" autocomplete="new-password"
                               class="w-full border rounded px-3 py-2 font-mono text-sm"
                               placeholder="{{ $setting->webhook_secret ? '•••••••• (tersimpan — kosongkan bila tidak diubah)' : 'Signing secret dari dasbor Webhooks' }}">
                        <p class="text-xs text-gray-400 mt-1">
                            Harus <b>sama persis</b> dengan yang ada di dasbor vendor. Selama kosong, webhook masuk
                            ditolak dengan 503 — sengaja, supaya tidak ada pesan tak terverifikasi yang masuk basis data.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">Base URL</label>
                            <input type="text" name="base_url" value="{{ old('base_url', $setting->base_url) }}"
                                   class="w-full border rounded px-3 py-2 font-mono text-xs"
                                   placeholder="{{ \App\Models\CrmSetting::DEFAULT_BASE_URL['apicoid'] }}">
                            <p class="text-xs text-gray-400 mt-1">Kosongkan untuk memakai bawaan.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1">ID Nomor Bisnis Default</label>
                            <input type="text" name="default_phone_number_id" value="{{ old('default_phone_number_id', $setting->default_phone_number_id) }}"
                                   class="w-full border rounded px-3 py-2 font-mono text-xs" placeholder="kosong = nomor utama akun">
                            <p class="text-xs text-gray-400 mt-1">Lihat daftar nomor di bawah setelah uji koneksi.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== Pengaman pengiriman ===== --}}
            <div class="border-t pt-5">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Pengaman Pengiriman</h3>

                <label class="inline-flex items-start gap-2 text-sm font-semibold text-gray-700 mb-4">
                    <input type="checkbox" name="dry_run" value="1" {{ old('dry_run', $nilai['dry_run']) ? 'checked' : '' }}
                           class="rounded border-gray-300 mt-0.5">
                    <span>
                        Mode jangan-kirim (dry run)
                        <span class="block text-xs font-normal text-gray-400">
                            Menyala = seluruh ERP memakai driver palsu. Matikan hanya setelah nomor, template,
                            dan webhook benar-benar terbukti.
                        </span>
                    </span>
                </label>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Daftar Putih Penerima</label>
                    <textarea name="allowed_recipients" rows="3"
                              class="w-full border rounded px-3 py-2 font-mono text-sm"
                              placeholder="0855-777-4446&#10;628998844666">{{ old('allowed_recipients', $nilai['allowed_recipients']) }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">
                        Satu nomor per baris (bentuk apa pun — dinormalkan otomatis). Selama tidak kosong,
                        pengiriman ke nomor di luar daftar ditolak <b>di dalam adapter, sebelum menyentuh jaringan</b>.
                        Kosongkan hanya setelah uji nyata selesai.
                    </p>
                </div>
            </div>

            {{-- ===== Jalur notifikasi (pindah ke layar WAHA) ===== --}}
            <div class="border-t pt-5">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Jalur Notifikasi</h3>
                <div class="rounded-lg border bg-gray-50 px-4 py-3 text-sm text-gray-600">
                    Notifikasi pesanan sekarang lewat
                    <b>{{ $nilai['notifikasi_driver'] === 'waha' ? 'WAHA (self-host, tak resmi)' : 'template resmi Meta (berbayar)' }}</b>.
                    Kredensial, nama sesi, dan QR penautan nomornya ada di layar sendiri &mdash;
                    <a href="{{ route('settings.waha.edit') }}" class="text-blue-600 hover:underline font-semibold">WhatsApp Notifikasi (WAHA)</a>.
                    <span class="block text-xs text-gray-400 mt-1">
                        Saklar jangan-kirim &amp; daftar putih penerima di halaman ini berlaku untuk <b>kedua</b> jalur.
                    </span>
                </div>
            </div>

            {{-- ===== Jam toko & lampiran ===== --}}
            <div class="border-t pt-5">
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Jam Toko &amp; Lampiran</h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-3">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Kalimat Jam Toko</label>
                        <input type="text" name="store_hours_text" value="{{ old('store_hours_text', $nilai['store_hours_text']) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">
                            Dibaca pelanggan pada template <span class="font-mono">pesanan_siap_diambil</span> (<span class="font-mono">&#123;&#123;4&#125;&#125;</span>).
                            <b>Wajib sejalan</b> dengan jam buka/tutup di bawah — beda = pelanggan diberi tahu jam yang bukan jam buka.
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Jam Buka</label>
                        <input type="number" name="store_open_hour" min="0" max="23" value="{{ old('store_open_hour', $nilai['store_open_hour']) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Jam Tutup</label>
                        <input type="number" name="store_close_hour" min="1" max="24" value="{{ old('store_close_hour', $nilai['store_close_hour']) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">Penjadwal "jam sopan" untuk notifikasi siap diambil.</p>
                    </div>
                    <div></div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Batas Ukuran Lampiran (MB)</label>
                        <input type="number" name="max_media_mb" min="1" max="200" value="{{ old('max_media_mb', $nilai['max_media_mb']) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Masa Simpan Lampiran (hari)</label>
                        <input type="number" name="attachment_retention_days" min="30" max="3650" value="{{ old('attachment_retention_days', $nilai['attachment_retention_days']) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">
                            Hanya menyapu lampiran di percakapan yang <b>tak tertaut apa pun</b>. Yang sudah punya
                            pelanggan atau dokumen tidak pernah dihapus.
                        </p>
                    </div>
                </div>
            </div>

            <div class="pt-1">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>

        {{-- ===== Diagnostik ===== --}}
        <hr class="my-6">
        <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Koneksi &amp; Webhook</h3>

        @if($setting->webhook_secret)
            <div class="bg-gray-50 border rounded-lg p-3 mb-4">
                <p class="text-xs text-gray-500 mb-1">
                    <span class="px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-semibold">Aktif</span>
                    URL webhook ERP (salin ke dasbor api.co.id &rarr; Webhooks):
                </p>
                <input type="text" readonly value="{{ $webhookUrl }}"
                       class="w-full border rounded px-3 py-2 font-mono text-xs bg-white" onclick="this.select()">
                <p class="text-xs text-gray-400 mt-1">
                    Rahasia Webhook terisi, jadi penjaganya <b>HMAC-SHA256 atas raw body</b> &mdash; jalur terkuat.
                    URL bertoken di bawah diabaikan selama ini terisi.
                </p>
            </div>

            <details class="mb-4">
                <summary class="text-xs text-gray-500 cursor-pointer">URL cadangan bertoken (tidak dipakai saat ini)</summary>
                <input type="text" readonly value="{{ $webhookTokenUrl }}"
                       class="w-full border rounded px-3 py-2 font-mono text-xs bg-white mt-2" onclick="this.select()">
            </details>
        @else
            <div class="bg-gray-50 border rounded-lg p-3 mb-2">
                <p class="text-xs text-gray-500 mb-1">
                    <span class="px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-semibold">Aktif</span>
                    URL webhook ERP (salin ke dasbor api.co.id &rarr; Webhooks):
                </p>
                <input type="text" readonly value="{{ $webhookTokenUrl }}"
                       class="w-full border rounded px-3 py-2 font-mono text-xs bg-white" onclick="this.select()">
                <p class="text-xs text-gray-400 mt-1">
                    Rahasia Webhook (HMAC) belum diisi, jadi yang menjaga adalah <b>token acak di dalam URL</b> ini
                    &mdash; pola yang sama dengan webhook Telegram &amp; QRISLY di ERP ini.
                    <b>Perlakukan URL ini seperti kata sandi</b>: jangan ditempel di chat grup atau tiket publik.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <form method="POST" action="{{ route('settings.crm.token-baru') }}"
                      onsubmit="return confirm('Token baru dibuat dan URL lama langsung mati. Anda harus memasang URL baru di dasbor api.co.id, kalau tidak pesan berhenti masuk. Lanjutkan?')">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 border border-gray-300 hover:bg-gray-50 rounded text-xs font-semibold text-gray-700">Buat Ulang Token</button>
                </form>
                <span class="text-xs text-gray-400">Begitu signing secret vendor ketemu, isi kolom HMAC di atas &mdash; ia otomatis jadi penjaga utama.</span>
            </div>

            <details class="mb-4">
                <summary class="text-xs text-gray-500 cursor-pointer">URL tanpa token (butuh HMAC, sekarang menolak 503)</summary>
                <input type="text" readonly value="{{ $webhookUrl }}"
                       class="w-full border rounded px-3 py-2 font-mono text-xs bg-white mt-2" onclick="this.select()">
            </details>
        @endif

        @if($numbers)
            <div class="mb-4">
                @if($numbers['success'])
                    <p class="text-xs font-semibold text-gray-500 mb-2">Nomor bisnis terhubung</p>
                    <div class="overflow-x-auto border rounded-lg">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">Nomor</th>
                                    <th class="px-3 py-2 text-left font-semibold">Nama Terverifikasi</th>
                                    <th class="px-3 py-2 text-left font-semibold">Kualitas</th>
                                    <th class="px-3 py-2 text-left font-semibold">phone_number_id</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach($numbers['numbers'] as $n)
                                    <tr>
                                        <td class="px-3 py-2 font-medium">{{ $n['display'] ?: '—' }} @if($n['is_primary'])<span class="ml-1 text-amber-500">★</span>@endif</td>
                                        <td class="px-3 py-2">{{ $n['verified_name'] ?: '—' }}</td>
                                        <td class="px-3 py-2">{{ $n['quality_rating'] ?: '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-gray-500">{{ $n['phone_number_id'] ?: $n['id'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-xs rounded-lg px-3 py-2 bg-red-50 text-red-700 border border-red-200">
                        Tidak bisa membaca daftar nomor: {{ $numbers['error'] }}
                    </div>
                @endif
            </div>
        @else
            <p class="text-xs text-gray-500 mb-4">Isi API Key, centang "Aktifkan", lalu simpan untuk melihat nomor bisnis &amp; status webhook.</p>
        @endif

        @if($webhooks && $webhooks['success'])
            <p class="text-xs font-semibold text-gray-500 mb-2">Endpoint webhook di vendor</p>
            <div class="overflow-x-auto border rounded-lg mb-3">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">URL</th>
                            <th class="px-3 py-2 text-left font-semibold">Status</th>
                            <th class="px-3 py-2 text-left font-semibold">Gagal</th>
                            <th class="px-3 py-2 text-left font-semibold">Alasan Dimatikan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($webhooks['endpoints'] as $e)
                            <tr>
                                <td class="px-3 py-2 font-mono">{{ $e['url'] ?: '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded-full {{ $e['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                        {{ $e['is_active'] ? 'Aktif' : 'Mati' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">{{ $e['failure_count'] }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ $e['disable_reason'] ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-3 text-center text-gray-400">Belum ada endpoint terdaftar di dasbor vendor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif($webhooks)
            <div class="text-xs rounded-lg px-3 py-2 bg-red-50 text-red-700 border border-red-200 mb-3">
                Tidak bisa membaca daftar webhook: {{ $webhooks['error'] }}
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('settings.crm.uji') }}">
                @csrf
                <button type="submit" class="px-3 py-2 border border-gray-300 hover:bg-gray-50 rounded text-sm font-semibold text-gray-700">Uji Koneksi</button>
            </form>
            <form method="POST" action="{{ route('settings.crm.aktifkan-webhook') }}">
                @csrf
                <button type="submit" class="px-3 py-2 border border-emerald-300 text-emerald-700 hover:bg-emerald-50 rounded text-sm font-semibold">Aktifkan Ulang Webhook</button>
            </form>
        </div>
        <p class="text-xs text-gray-400 mt-2">
            Vendor mematikan endpoint <b>diam-diam</b> setelah gagal beruntun — sejak itu tak ada pesan
            masuk yang sampai ke ERP, tanpa gejala apa pun. Periksa tabel di atas bila inbox tiba-tiba sepi.
        </p>
    </div>
</div>
@endsection
