@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto" x-data="{ provider: '{{ old('confirmation_provider', $setting->confirmation_provider ?: 'email') }}' }">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Pembayaran — Transfer Bank + Kode Unik</h2>
        <p class="text-sm text-gray-500 mb-6">
            Pengganti Midtrans untuk toko online. Pembeli transfer nominal <b>unik</b> (grand total dikurangi
            kode unik Rp1–999); sistem mencocokkan uang masuk secara otomatis (email/Moota),
            lalu <b>eskalasi ke Telegram</b> bila belum tercocokkan, dan <b>auto-batal</b> bila lewat batas waktu.
        </p>

        <form method="POST" action="{{ route('settings.payment.update') }}" class="space-y-6">
            @csrf

            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $setting->is_active) ? 'checked' : '' }}
                       class="rounded border-gray-300">
                Aktifkan pembayaran transfer bank
            </label>

            {{-- Rekening tujuan (bisa lebih dari satu: mis. BRI auto-email + BCA manual-Telegram) --}}
            @php
                $initRows = collect(old('bank_accounts', $setting->accounts()))->map(fn($r) => [
                    'bank_name'       => $r['bank_name'] ?? '',
                    'account_number'  => $r['account_number'] ?? '',
                    'account_holder'  => $r['account_holder'] ?? '',
                    'cash_account_id' => isset($r['cash_account_id']) ? (string) $r['cash_account_id'] : '',
                    'confirmation'    => ($r['confirmation'] ?? 'email') === 'manual' ? 'manual' : 'email',
                ])->values();
                if ($initRows->isEmpty()) {
                    $initRows = collect([['bank_name' => '', 'account_number' => '', 'account_holder' => '', 'cash_account_id' => '', 'confirmation' => 'email']]);
                }
            @endphp
            <div class="border-t border-gray-100 pt-5"
                 x-data="{ rows: @js($initRows) }">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-700">Rekening Tujuan</h3>
                    <button type="button" class="text-xs text-blue-600 hover:underline"
                            @click="rows.push({bank_name:'',account_number:'',account_holder:'',cash_account_id:'',confirmation:'email'})">+ Tambah Rekening</button>
                </div>

                <template x-for="(row, i) in rows" :key="i">
                    <div class="border border-gray-100 rounded p-3 mb-3 bg-gray-50/50">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Bank</label>
                                <input type="text" x-model="row.bank_name" :name="`bank_accounts[${i}][bank_name]`"
                                       placeholder="BRI / BCA" class="w-full border rounded px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">No. Rekening</label>
                                <input type="text" x-model="row.account_number" :name="`bank_accounts[${i}][account_number]`"
                                       class="w-full border rounded px-3 py-2 text-sm font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Atas Nama</label>
                                <input type="text" x-model="row.account_holder" :name="`bank_accounts[${i}][account_holder]`"
                                       class="w-full border rounded px-3 py-2 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3 items-end">
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Akun Kas ERP</label>
                                {{-- Opsi dirender server-side (BUKAN <template x-for>): x-model menyeleksi
                                     nilai awal dengan menelusuri el.options saat init, sedangkan x-for bersarang
                                     baru mengisi <option> setelahnya → pilihan tersimpan tampil kosong dan
                                     ikut terkirim kosong saat disimpan ulang. --}}
                                <select x-model="row.cash_account_id" :name="`bank_accounts[${i}][cash_account_id]`"
                                        class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="">— akun kas —</option>
                                    @foreach ($cashAccounts as $a)
                                        <option value="{{ $a->id }}">{{ $a->code }} — {{ $a->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-1">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Konfirmasi</label>
                                <select x-model="row.confirmation" :name="`bank_accounts[${i}][confirmation]`"
                                        class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="email">Auto (email notifikasi)</option>
                                    <option value="manual">Manual (Telegram)</option>
                                </select>
                            </div>
                            <div class="sm:col-span-1 text-right">
                                <button type="button" class="text-xs text-red-600 hover:underline"
                                        @click="rows.splice(i, 1)" x-show="rows.length > 1">Hapus rekening</button>
                            </div>
                        </div>
                    </div>
                </template>
                <p class="text-xs text-gray-400">
                    Rekening ber-mode <b>Auto (email)</b> dikonfirmasi otomatis dari email bank (mis. BRI).
                    Mode <b>Manual (Telegram)</b> dikonfirmasi lewat tombol di Telegram (mis. BCA — tidak kirim email masuk).
                </p>
            </div>

            {{-- Kode unik & timer --}}
            <div class="border-t border-gray-100 pt-5">
                <h3 class="text-sm font-bold text-gray-700 mb-3">Kode Unik &amp; Batas Waktu</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Kode Min</label>
                        <input type="number" name="unique_code_min" value="{{ old('unique_code_min', $setting->unique_code_min ?: 1) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Kode Maks</label>
                        <input type="number" name="unique_code_max" value="{{ old('unique_code_max', $setting->unique_code_max ?: 999) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Eskalasi (menit)</label>
                        <input type="number" name="escalation_minutes" value="{{ old('escalation_minutes', $setting->escalation_minutes ?: 10) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Kedaluwarsa (jam)</label>
                        <input type="number" name="expiry_hours" value="{{ old('expiry_hours', $setting->expiry_hours ?: 24) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">
                    Kode unik dikurangkan dari total agar nominal transfer unik (dipakai mencocokkan pembayaran).
                    Eskalasi = kirim ke Telegram bila belum tercocokkan setelah pembeli menyatakan sudah transfer.
                </p>
            </div>

            {{-- QRIS (QRISLY / Komerce) --}}
            @php
                $qrisId     = $setting->conf('qris_id');
                $qrisSecret = $setting->qrisWebhookSecret();
            @endphp
            <div id="qris" class="border-t border-gray-100 pt-5 scroll-mt-24">
                <h3 class="text-sm font-bold text-gray-700 mb-3">QRIS (QRISLY — Komerce)</h3>

                <label class="flex items-center gap-2 text-sm mb-3">
                    <input type="hidden" name="qris_enabled" value="0">
                    <input type="checkbox" name="qris_enabled" value="1" class="w-4 h-4 accent-blue-600"
                           {{ old('qris_enabled', $setting->conf('qris_enabled')) ? 'checked' : '' }}>
                    <span>Aktifkan QRIS sebagai metode pembayaran toko online</span>
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">API Key QRISLY</label>
                        <input type="password" name="qris_api_key" autocomplete="new-password"
                               placeholder="{{ $setting->conf('qris_api_key') ? '•••••• (biarkan kosong = tidak diubah)' : 'Tempel API key QRISLY' }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Lingkungan</label>
                        <select name="qris_env" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="sandbox"    @selected(old('qris_env', $setting->conf('qris_env', 'sandbox')) === 'sandbox')>Sandbox (uji coba)</option>
                            <option value="production" @selected(old('qris_env', $setting->conf('qris_env')) === 'production')>Production (live)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">QRIS ID (statis)</label>
                        <input type="text" name="qris_id" value="{{ old('qris_id', $setting->conf('qris_id')) }}"
                               placeholder="Salin dari dashboard Komerce bila QRIS diunggah di sana"
                               class="w-full border rounded px-3 py-2 text-sm font-mono">
                        <p class="text-[10px] text-gray-400 mt-1">Isi manual bila QRIS statis sudah diunggah lewat dashboard RajaOngkir/Komerce; atau kosongkan dan pakai form unggah di bawah.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Akun Kas Penampung QRIS</label>
                        <select name="qris_cash_account_id" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">— pilih akun —</option>
                            @foreach($cashAccounts as $acc)
                                <option value="{{ $acc->id }}" @selected(old('qris_cash_account_id', $setting->qrisCashAccountId()) == $acc->id)>
                                    {{ $acc->code }} — {{ $acc->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">Uang QRIS baru masuk rekening BCA H+1 &amp; sudah dipotong MDR, jadi ditampung di akun ini dulu.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Akun Beban MDR (opsional)</label>
                        <select name="qris_fee_account_id" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">— pilih akun —</option>
                            @foreach($expenseAccounts as $acc)
                                <option value="{{ $acc->id }}" @selected(old('qris_fee_account_id', $setting->qrisFeeAccountId()) == $acc->id)>
                                    {{ $acc->code }} — {{ $acc->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">Dipakai saat mencatat selisih settlement (potongan penerbit QRIS).</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Masa Berlaku QR (menit)</label>
                        <input type="number" name="qris_expiry_minutes" value="{{ old('qris_expiry_minutes', $setting->qrisExpiryMinutes()) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-[10px] text-gray-400 mt-1">QR dipakai ulang selama belum kedaluwarsa — tiap pembuatan QR baru berbiaya Rp100.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Eskalasi QRIS (menit)</label>
                        <input type="number" name="qris_escalation_minutes" value="{{ old('qris_escalation_minutes', $setting->qrisEscalationMinutes()) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-[10px] text-gray-400 mt-1">Lebih pendek dari transfer: deteksi QRIS bergantung HP listener.</p>
                    </div>
                </div>

                <div class="mt-4 rounded border border-gray-100 bg-gray-50 p-3 text-xs space-y-1">
                    <div>
                        <span class="font-semibold text-gray-600">QRIS statis terdaftar:</span>
                        @if($qrisId)
                            <span class="font-mono text-green-700">{{ $qrisId }}</span>
                        @else
                            <span class="text-amber-600">belum ada — unggah di bawah setelah menyimpan API key.</span>
                        @endif
                    </div>
                    <div class="break-all">
                        <span class="font-semibold text-gray-600">URL webhook (daftarkan di dashboard Komerce → Developer → Webhook):</span><br>
                        @if($qrisSecret)
                            @php
                                $webhookUrl = url('/qrisly/webhook/' . $qrisSecret);
                                $host       = parse_url($webhookUrl, PHP_URL_HOST);
                                $isLocal    = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
                            @endphp
                            <span class="font-mono text-gray-700">{{ $webhookUrl }}</span>
                            @if($isLocal)
                                <div class="mt-1 text-amber-600">
                                    ⚠️ Ini alamat lokal — <b>jangan didaftarkan</b>, Komerce tidak bisa menjangkaunya dari internet.
                                    Pakai URL yang muncul di halaman ini <b>saat dibuka dari server</b> (erp.noudakrilik.com);
                                    rahasianya berbeda karena databasenya terpisah.
                                </div>
                            @endif
                            <div class="mt-1 text-gray-400">
                                Tanpa webhook pun pembayaran tetap terdeteksi lewat pengecekan berkala tiap 2 menit —
                                webhook hanya membuatnya hampir seketika.
                            </div>
                        @else
                            <span class="text-gray-400">akan muncul setelah pengaturan disimpan sekali.</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Konfirmasi otomatis --}}
            <div class="border-t border-gray-100 pt-5">
                <h3 class="text-sm font-bold text-gray-700 mb-3">Konfirmasi Otomatis</h3>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Sumber Konfirmasi</label>
                <select name="confirmation_provider" x-model="provider" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="email">Email Notifikasi Bank (IMAP)</option>
                    <option value="moota">Moota / Mutasibank (webhook)</option>
                    <option value="manual">Manual saja (andalkan Telegram)</option>
                </select>

                {{-- IMAP --}}
                <div x-show="provider === 'email'" x-cloak class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">IMAP Host</label>
                        <input type="text" name="imap_host" value="{{ old('imap_host', $setting->conf('imap_host')) }}"
                               placeholder="imap.gmail.com" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Port</label>
                            <input type="number" name="imap_port" value="{{ old('imap_port', $setting->conf('imap_port', 993)) }}"
                                   class="w-full border rounded px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Enkripsi</label>
                            <select name="imap_encryption" class="w-full border rounded px-3 py-2 text-sm">
                                @php $enc = old('imap_encryption', $setting->conf('imap_encryption', 'ssl')); @endphp
                                <option value="ssl" {{ $enc === 'ssl' ? 'selected' : '' }}>SSL</option>
                                <option value="tls" {{ $enc === 'tls' ? 'selected' : '' }}>TLS</option>
                                <option value="" {{ $enc === '' ? 'selected' : '' }}>Tanpa</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Username / Email</label>
                        <input type="text" name="imap_username" value="{{ old('imap_username', $setting->conf('imap_username')) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Password / App Password</label>
                        <input type="password" name="imap_password" autocomplete="new-password"
                               placeholder="{{ $setting->conf('imap_password') ? '•••••• (tersimpan)' : '' }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Folder</label>
                        <input type="text" name="imap_folder" value="{{ old('imap_folder', $setting->conf('imap_folder', 'INBOX')) }}"
                               class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Filter Pengirim (opsional, pisah koma)</label>
                        <input type="text" name="sender_filter" value="{{ old('sender_filter', $setting->conf('sender_filter')) }}"
                               placeholder="bri.co.id, bca.co.id" class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">Hanya email dari alamat/domain ini yang diproses. Kosong = semua pengirim (tetap disaring kata kredit).</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Kata Kunci Transaksi Masuk</label>
                        <input type="text" name="credit_keywords" value="{{ old('credit_keywords', $setting->conf('credit_keywords', 'masuk,kredit,diterima')) }}"
                               placeholder="masuk, kredit, diterima" class="w-full border rounded px-3 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">Email WAJIB memuat salah satu kata ini → transaksi <b>keluar</b> tidak salah dicocokkan.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Regex Nominal (opsional)</label>
                        <input type="text" name="amount_regex" value="{{ old('amount_regex', $setting->conf('amount_regex')) }}"
                               placeholder="default: /Rp\.?\s*([\d][\d.,]*)/i" class="w-full border rounded px-3 py-2 text-sm font-mono">
                        <p class="text-xs text-gray-400 mt-1">Kosongkan untuk memakai pola default (cocok utk BRI &amp; BCA). Butuh ekstensi PHP <span class="font-mono">imap</span> aktif di server.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="mark_seen" value="1" {{ old('mark_seen', $setting->conf('mark_seen', true)) ? 'checked' : '' }} class="rounded border-gray-300">
                            Tandai email bank sebagai sudah dibaca setelah diproses
                        </label>
                    </div>
                </div>

                {{-- Moota --}}
                <div x-show="provider === 'moota'" x-cloak class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Moota API Token</label>
                        <input type="text" name="moota_token" value="{{ old('moota_token', $setting->conf('moota_token')) }}"
                               class="w-full border rounded px-3 py-2 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Webhook Secret</label>
                        <input type="text" name="moota_secret" value="{{ old('moota_secret', $setting->conf('moota_secret')) }}"
                               class="w-full border rounded px-3 py-2 text-sm font-mono">
                    </div>
                    <p class="sm:col-span-2 text-xs text-gray-400">Moota bersifat webhook (push). Endpoint webhook menyusul.</p>
                </div>
            </div>

            <div class="pt-2 border-t border-gray-100">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>
    </div>

    {{-- Unggah QRIS statis — form terpisah (upload berkas, bukan bagian form utama) --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
        <h3 class="text-sm font-bold text-gray-700 mb-1">Unggah QRIS Statis</h3>
        <p class="text-xs text-gray-500 mb-4">
            Cukup sekali. Ambil gambar QRIS statis dari aplikasi <b>Merchant BCA</b>, unggah di sini →
            QRISLY mengembalikan <span class="font-mono">qris_id</span> yang dipakai membuat QRIS dinamis tiap pesanan.
            Isi &amp; simpan API key dulu di atas.
        </p>
        <form method="POST" action="{{ route('settings.payment.qris-upload') }}" enctype="multipart/form-data"
              class="flex flex-col sm:flex-row sm:items-end gap-3">
            @csrf
            <div class="flex-1">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Berkas QRIS (PNG/JPG)</label>
                <input type="file" name="qris_image" accept="image/png,image/jpeg" required
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div class="flex-1">
                <label class="block text-xs font-semibold text-gray-600 mb-1">Nama Identitas</label>
                <input type="text" name="qris_name" value="{{ $setting->bank_name ? 'Noud Acrylic' : 'Noud Acrylic' }}"
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <button type="submit" class="px-4 py-2 border border-blue-600 text-blue-600 hover:bg-blue-50 rounded text-sm font-semibold whitespace-nowrap">
                Unggah ke QRISLY
            </button>
        </form>
    </div>
</div>
@endsection
