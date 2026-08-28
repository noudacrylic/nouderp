@extends('layouts.erp')

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>
    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Pengaturan Midtrans</h2>
        <p class="text-sm text-gray-500 mb-6">
            Kredensial, masa berlaku link, tarif potongan (MDR), dan biaya admin yang dibebankan ke customer.
            Semua transaksi Midtrans dibukukan ke akun <b>Saldo Midtrans</b> (kas tunggal) agar cocok dengan saldo gabungan di dashboard Midtrans.
        </p>


        {{-- Webhook URL untuk di-paste ke Dashboard Midtrans --}}
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
            <h3 class="text-sm font-bold text-amber-800 mb-1">Payment Notification URL</h3>
            <p class="text-xs text-amber-700 mb-2">
                Paste ke <b>Midtrans Dashboard → Settings → Configuration → Payment Notification URL</b>.
                URL ini otomatis mengikuti domain aplikasi (saat ini: <span class="font-mono">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</span>).
            </p>
            <div class="flex gap-2">
                <input type="text" readonly value="{{ route('midtrans.notify') }}"
                       class="flex-1 border rounded px-3 py-2 font-mono text-xs bg-white" onclick="this.select()">
                <button type="button"
                        onclick="navigator.clipboard.writeText('{{ route('midtrans.notify') }}'); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Copy',1500)"
                        class="px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded text-sm font-semibold whitespace-nowrap">Copy</button>
            </div>
        </div>

        {{-- Jaring pengaman kalau notifikasi tidak sampai. Form terpisah dari form
             pengaturan di bawahnya supaya menekan tombol ini tidak ikut menyimpan
             kunci yang mungkin sedang setengah diketik. --}}
        @php $pending = \App\Models\MidtransTransaction::where('status', 'pending')->count(); @endphp
        <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-800 mb-1">Status Pembayaran Tertunda</h3>
                    <p class="text-xs text-slate-600">
                        Ada <b>{{ number_format($pending, 0, ',', '.') }}</b> transaksi berstatus <i>pending</i>.
                        Status ditarik otomatis tiap 15 menit; tombol ini menariknya sekarang juga —
                        berguna saat pelanggan bilang sudah membayar tapi ERP belum mencatatnya.
                    </p>
                </div>
                <form method="POST" action="{{ route('settings.midtrans.reconcile') }}">
                    @csrf
                    <button class="border border-slate-300 hover:bg-slate-100 text-slate-700 px-4 py-2 rounded text-sm font-semibold whitespace-nowrap transition">
                        Tarik Status Sekarang
                    </button>
                </form>
            </div>
        </div>

        <form method="POST" action="{{ route('settings.midtrans.update') }}" class="space-y-8">
            @csrf

            {{-- ===== Kredensial ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Kredensial</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Server Key</label>
                        <input type="text" name="server_key" value="{{ old('server_key', $setting->server_key) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="SB-Mid-server-...">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Client Key</label>
                        <input type="text" name="client_key" value="{{ old('client_key', $setting->client_key) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="SB-Mid-client-...">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Merchant ID</label>
                        <input type="text" name="merchant_id" value="{{ old('merchant_id', $setting->merchant_id) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="G123456789">
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <input type="checkbox" name="is_production" value="1" {{ old('is_production', $setting->is_production) ? 'checked' : '' }}
                                   class="rounded border-gray-300">
                            Mode Production
                        </label>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Jangan aktifkan Production sebelum punya key production yang valid.</p>
            </div>

            {{-- ===== Tampilan cara pembayaran di cetakan ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Cara Pembayaran di Cetakan</h3>
                <label class="inline-flex items-start gap-2 text-sm font-semibold text-gray-700">
                    <input type="checkbox" name="show_payment_method" value="1" {{ old('show_payment_method', $setting->show_payment_method) ? 'checked' : '' }}
                           class="rounded border-gray-300 mt-0.5">
                    <span>Tampilkan pembayaran Midtrans di cetak Invoice/SO</span>
                </label>
                <p class="text-xs text-gray-500 mt-2">
                    <b>Dicentang:</b> cetakan Faktur &amp; Pesanan menampilkan cara pembayaran Midtrans (link bayar + QR).
                    <b>Tidak dicentang:</b> cetakan menampilkan <b>nomor rekening</b> untuk transfer manual.
                    Biarkan <b>tidak dicentang</b> selama akun Midtrans masih dalam proses review bisnis.
                </p>
            </div>

            {{-- ===== Tombol QRIS di Kasir POS ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Kasir POS</h3>
                <label class="inline-flex items-start gap-2 text-sm font-semibold text-gray-700">
                    <input type="checkbox" name="pos_qris_enabled" value="1" {{ old('pos_qris_enabled', $setting->pos_qris_enabled) ? 'checked' : '' }}
                           class="rounded border-gray-300 mt-0.5">
                    <span>Tampilkan tombol <b>Bayar QRIS</b> di Kasir</span>
                </label>
                <p class="text-xs text-gray-500 mt-2">
                    <b>Tidak dicentang (disarankan selama Midtrans belum live):</b> tombol Bayar QRIS
                    disembunyikan sehingga kasir tidak bisa salah klik dan membuat faktur ter-post
                    <b>tanpa pembayaran</b> (nyangkut BELUM LUNAS). Kasir cukup pakai <b>Bayar Cash</b>.
                </p>
            </div>

            {{-- ===== Masa berlaku ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Masa Berlaku</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Masa Berlaku Kode Bayar (hari)</label>
                        <input type="number" min="1" max="90" name="link_expiry_days"
                               value="{{ old('link_expiry_days', $setting->link_expiry_days) }}" class="w-full border rounded px-3 py-2">
                        <p class="text-xs text-gray-500 mt-1">
                            Umur QRIS/VA yang terbit saat pembeli menekan <b>Bayar</b>. Tautan
                            <b>/pay</b>-nya sendiri tidak berbatas waktu &mdash; pembeli memakainya lagi
                            untuk memantau pesanan &amp; mengunduh nota.
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Masa Berlaku QRIS (menit)</label>
                        <input type="number" min="5" max="1440" name="qris_expiry_minutes"
                               value="{{ old('qris_expiry_minutes', $setting->qris_expiry_minutes) }}" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
            </div>

            {{-- Field lama disimpan sebagai fallback (dipakai bila channel_fees kosong). --}}
            <input type="hidden" name="va_fee" value="{{ old('va_fee', (int) $setting->va_fee) }}">
            <input type="hidden" name="qris_fee_percent" value="{{ old('qris_fee_percent', $setting->qris_fee_percent) }}">
            <input type="hidden" name="customer_fee_threshold" value="{{ old('customer_fee_threshold', (int) $setting->customer_fee_threshold) }}">
            <input type="hidden" name="customer_fee_amount" value="{{ old('customer_fee_amount', (int) $setting->customer_fee_amount) }}">

            {{-- ===== Tarif & subsidi per metode ===== --}}
            @php
                $cfLabels = \App\Modules\Payment\Services\MidtransFeeCalculator::channelLabels();
                $cfDefaults = \App\Modules\Payment\Services\MidtransFeeCalculator::channelDefaults();
                $cfStored = old('channel_fees', $setting->channel_fees ?? []);
                $aktifChannels = (array) old('active_channels', $setting->activeChannels());
            @endphp
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-1">Tarif &amp; Subsidi per Metode</h3>
                <p class="text-xs text-gray-500 mb-3">
                    <b>MDR</b> = potongan Midtrans. <b>Subsidi</b> = bagian yang toko tanggung.
                    Yang dibebankan ke pembeli = <b>MDR − subsidi</b> = <code>(MDR% − Subsidi%) × nominal + (MDR Rp − Subsidi Rp)</code>.
                    Biaya <b>flat (Rp)</b> hanya dikenakan bila nominal <b>di bawah Batas</b> (isi <b>0</b> = selalu dikenakan).
                    Isi Subsidi = MDR agar pembeli <b>tidak</b> dikenakan biaya.
                </p>
                <div class="overflow-x-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 text-xs">
                            <tr>
                                <th class="px-2 py-2 font-semibold">Aktif</th>
                                <th class="text-left px-3 py-2 font-semibold">Metode</th>
                                <th class="px-2 py-2 font-semibold">MDR %</th>
                                <th class="px-2 py-2 font-semibold">MDR Rp</th>
                                <th class="px-2 py-2 font-semibold">Subsidi %</th>
                                <th class="px-2 py-2 font-semibold">Subsidi Rp</th>
                                <th class="px-2 py-2 font-semibold">Batas flat (Rp)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($cfLabels as $key => $label)
                                @php $row = ($cfStored[$key] ?? null) ?: $cfDefaults[$key]; @endphp
                                <tr>
                                    <td class="px-2 py-1 text-center">
                                        <input type="checkbox" name="active_channels[]" value="{{ $key }}"
                                               {{ in_array($key, $aktifChannels, true) ? 'checked' : '' }}
                                               class="rounded border-gray-300">
                                    </td>
                                    <td class="px-3 py-2 font-semibold text-gray-700 whitespace-nowrap">{{ $label }}</td>
                                    <td class="px-2 py-1"><input type="number" min="0" max="100" step="0.001" name="channel_fees[{{ $key }}][mdr_percent]"     value="{{ $row['mdr_percent'] ?? 0 }}"     class="w-20 border rounded px-2 py-1 text-right"></td>
                                    <td class="px-2 py-1"><input type="number" min="0" step="1"              name="channel_fees[{{ $key }}][mdr_flat]"        value="{{ (int)($row['mdr_flat'] ?? 0) }}"        class="w-24 border rounded px-2 py-1 text-right"></td>
                                    <td class="px-2 py-1"><input type="number" min="0" max="100" step="0.001" name="channel_fees[{{ $key }}][subsidy_percent]" value="{{ $row['subsidy_percent'] ?? 0 }}" class="w-20 border rounded px-2 py-1 text-right"></td>
                                    <td class="px-2 py-1"><input type="number" min="0" step="1"              name="channel_fees[{{ $key }}][subsidy_flat]"    value="{{ (int)($row['subsidy_flat'] ?? 0) }}"    class="w-24 border rounded px-2 py-1 text-right"></td>
                                    <td class="px-2 py-1"><input type="number" min="0" step="1"              name="channel_fees[{{ $key }}][flat_threshold]"  value="{{ (int)($row['flat_threshold'] ?? 0) }}"  class="w-28 border rounded px-2 py-1 text-right"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-500 mt-2">Dipakai untuk (a) biaya admin yang tampil ke pembeli di halaman bayar, dan (b) pembukuan Beban Gateway otomatis saat settle. Sesuaikan tarif MDR dengan tarif asli Midtrans setelah channel aktif.</p>
                <p class="text-xs text-gray-500 mt-1">
                    <b>Kolom Aktif</b> menentukan metode yang <b>ditawarkan ke pembeli</b> di halaman bayar.
                    Alfamart, Kredivo/Akulaku, dan Kartu Kredit butuh <b>pengajuan terpisah ke Midtrans</b> —
                    jangan dicentang sebelum disetujui, karena pembeli yang memilihnya akan mentok di halaman Midtrans.
                    Tarif di baris yang tidak aktif tetap disimpan untuk pembukuan.
                </p>
            </div>

            {{-- ===== Akun ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Akun</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Saldo Midtrans (Kas)</label>
                        <select name="cash_account_id" class="w-full border rounded px-3 py-2">
                            @foreach($cashAccounts as $a)
                                <option value="{{ $a->id }}" {{ (int) old('cash_account_id', $setting->cash_account_id) === $a->id ? 'selected' : '' }}>
                                    {{ $a->code }} — {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Akun Beban Gateway</label>
                        <select name="fee_account_id" class="w-full border rounded px-3 py-2">
                            @foreach($expenseAccounts as $a)
                                <option value="{{ $a->id }}" {{ (int) old('fee_account_id', $setting->fee_account_id) === $a->id ? 'selected' : '' }}>
                                    {{ $a->code }} — {{ $a->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex justify-end border-t pt-4">
                <button class="bg-blue-600 text-white px-5 py-2 rounded font-semibold">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection
