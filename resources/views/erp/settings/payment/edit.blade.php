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
                $accOptions = $cashAccounts->map(fn($a) => ['id' => (string) $a->id, 'label' => $a->code.' — '.$a->name])->values();
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
                 x-data="{ rows: @js($initRows), opts: @js($accOptions) }">
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
                                <select x-model="row.cash_account_id" :name="`bank_accounts[${i}][cash_account_id]`"
                                        class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="">— akun kas —</option>
                                    <template x-for="o in opts" :key="o.id">
                                        <option :value="o.id" x-text="o.label"></option>
                                    </template>
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
</div>
@endsection
