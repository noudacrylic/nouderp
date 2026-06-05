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

        @if(session('success'))
            <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded text-sm mb-4">
                {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-4">
                @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
            </div>
        @endif

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

            {{-- ===== Masa berlaku ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Masa Berlaku</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Masa Berlaku Link (hari)</label>
                        <input type="number" min="1" max="90" name="link_expiry_days"
                               value="{{ old('link_expiry_days', $setting->link_expiry_days) }}" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Masa Berlaku QRIS (menit)</label>
                        <input type="number" min="5" max="1440" name="qris_expiry_minutes"
                               value="{{ old('qris_expiry_minutes', $setting->qris_expiry_minutes) }}" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
            </div>

            {{-- ===== Tarif MDR ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Tarif Potongan Midtrans (MDR)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">VA / Transfer Bank (Rp, flat)</label>
                        <input type="number" min="0" step="1" name="va_fee"
                               value="{{ old('va_fee', (int) $setting->va_fee) }}" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">QRIS (% dari nominal)</label>
                        <input type="number" min="0" max="100" step="0.001" name="qris_fee_percent"
                               value="{{ old('qris_fee_percent', $setting->qris_fee_percent) }}" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Dipakai membukukan beban gateway secara otomatis saat pembayaran settle (bukan dari webhook).</p>
            </div>

            {{-- ===== Biaya admin customer ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Biaya Admin Customer (VA/Transfer)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Batas Nominal (Rp)</label>
                        <input type="number" min="0" step="1" name="customer_fee_threshold"
                               value="{{ old('customer_fee_threshold', (int) $setting->customer_fee_threshold) }}" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Biaya Admin (Rp)</label>
                        <input type="number" min="0" step="1" name="customer_fee_amount"
                               value="{{ old('customer_fee_amount', (int) $setting->customer_fee_amount) }}" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Tagihan VA/Transfer di <b>bawah</b> batas nominal dikenakan biaya admin ini (ditanggung customer). QRIS tidak kena. Tarif VA tidak boleh lebih kecil dari biaya admin ini.</p>
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
