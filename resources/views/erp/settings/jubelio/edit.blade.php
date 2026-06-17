@extends('layouts.erp')

@section('content')
@php
    $accLabel = function ($id) use ($accounts) {
        if (!$id) return '';
        $a = $accounts->firstWhere('id', $id);
        return $a ? "{$a->code} — {$a->name}" : '';
    };
@endphp
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-3">
        <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline">← Integrasi</a>
        <a href="{{ route('settings.jubelio.history') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 hover:underline">
            Riwayat Sinkron →
        </a>
    </div>
    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Pengaturan Jubelio</h2>
        <p class="text-sm text-gray-500 mb-6">
            Integrasi omnichannel Jubelio. Pesanan marketplace otomatis menjadi Sales Order → Surat Jalan → Invoice di ERP,
            dan <b>stok ERP menjadi sumber kebenaran</b> yang didorong ke Jubelio. Stok ERP yang dipakai adalah <b>stok tersedia</b> (available).
        </p>


        {{-- Webhook URL untuk di-paste ke Jubelio (Pengaturan → Webhook/Integrasi) --}}
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
            <h3 class="text-sm font-bold text-amber-800 mb-1">Webhook URL (untuk Jubelio)</h3>
            <p class="text-xs text-amber-700 mb-3">
                Paste URL berikut ke pengaturan Webhook di Jubelio sesuai jenis event-nya.
                URL otomatis mengikuti domain aplikasi (saat ini: <span class="font-mono">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</span>).
                <b>Catatan:</b> isi juga <i>Webhook Secret</i> di bawah dan masukkan secret yang sama di Jubelio.
            </p>
            @foreach([
                ['Sales Order (pesanan baru/dibayar)', route('jubelio.webhook.salesorder')],
                ['Sales Return (retur)',               route('jubelio.webhook.salesreturn')],
                ['Stock (perubahan stok)',             route('jubelio.webhook.stock')],
            ] as [$label, $url])
                <div class="mb-2">
                    <div class="text-xs font-semibold text-amber-800 mb-1">{{ $label }}</div>
                    <div class="flex gap-2">
                        <input type="text" readonly value="{{ $url }}"
                               class="flex-1 border rounded px-3 py-2 font-mono text-xs bg-white" onclick="this.select()">
                        <button type="button"
                                onclick="navigator.clipboard.writeText('{{ $url }}'); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Copy',1500)"
                                class="px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded text-sm font-semibold whitespace-nowrap">Copy</button>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Sinkronisasi manual: reconcile stok sekarang (logika sama dgn cron 2-jam) --}}
        <div class="bg-sky-50 border border-sky-200 rounded-lg p-4 mb-6 flex items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-bold text-sky-800 mb-1">Cek &amp; Samakan Stok Sekarang</h3>
                <p class="text-xs text-sky-700">
                    Membaca stok aktual di Jubelio lalu mengoreksi selisihnya (ERP menang) — sama dengan
                    rekonsiliasi otomatis tiap 2 jam, tapi langsung. Hasilnya tercatat di <b>Riwayat Sinkron</b>.
                    Untuk banyak produk bisa butuh beberapa saat.
                </p>
            </div>
            <form method="POST" action="{{ route('settings.jubelio.reconcile') }}"
                  onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Menyamakan…';">
                @csrf
                <button type="submit"
                        class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded text-sm font-semibold whitespace-nowrap disabled:opacity-60">
                    Cek &amp; Samakan
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('settings.jubelio.update') }}" class="space-y-8">
            @csrf

            {{-- ===== Kredensial ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Kredensial</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Email Login Jubelio</label>
                        <input type="text" name="username" value="{{ old('username', $setting->username) }}"
                               class="w-full border rounded px-3 py-2 text-sm" placeholder="user@perusahaan.com" autocomplete="off">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" value=""
                               class="w-full border rounded px-3 py-2 text-sm" placeholder="{{ $setting->password ? '•••••••• (tersimpan)' : 'Password Jubelio' }}" autocomplete="new-password">
                        <p class="text-xs text-gray-400 mt-1">Kosongkan bila tidak ingin mengubah password tersimpan.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Base URL API</label>
                        <input type="text" name="base_url" value="{{ old('base_url', $setting->base_url) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-xs" placeholder="{{ \App\Modules\Marketplace\Jubelio\Models\JubelioSetting::DEFAULT_BASE_URL }}">
                    </div>
                    <div class="flex items-end gap-6">
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $setting->is_active) ? 'checked' : '' }}
                                   class="rounded border-gray-300">
                            Aktif
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                            <input type="checkbox" name="is_production" value="1" {{ old('is_production', $setting->is_production) ? 'checked' : '' }}
                                   class="rounded border-gray-300">
                            Mode Production
                        </label>
                    </div>
                </div>
            </div>

            {{-- ===== Default ERP ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Default Pemetaan</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Gudang ERP (SO/SJ)</label>
                        <select name="default_warehouse_id" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">— pilih gudang —</option>
                            @foreach($warehouses as $w)
                                <option value="{{ $w->id }}" {{ (int) old('default_warehouse_id', $setting->default_warehouse_id) === $w->id ? 'selected' : '' }}>
                                    {{ $w->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Location ID Jubelio (stok)</label>
                        <div class="flex gap-2">
                            <select name="default_location_id" id="jubelioLocation"
                                    class="flex-1 border rounded px-3 py-2 text-sm" data-current="{{ old('default_location_id', $setting->default_location_id) }}">
                                @if(old('default_location_id', $setting->default_location_id) !== null && old('default_location_id', $setting->default_location_id) !== '')
                                    <option value="{{ old('default_location_id', $setting->default_location_id) }}" selected>
                                        Tersimpan: {{ old('default_location_id', $setting->default_location_id) }}
                                    </option>
                                @else
                                    <option value="">— klik "Cari Lokasi" —</option>
                                @endif
                            </select>
                            <button type="button" id="btnFindLocation"
                                    class="px-3 py-2 border border-blue-600 text-blue-600 rounded text-sm font-semibold hover:bg-blue-50 whitespace-nowrap disabled:opacity-60">
                                Cari Lokasi
                            </button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1" id="jubelioLocationHint">Klik "Cari Lokasi" untuk menarik daftar lokasi dari Jubelio.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Customer Marketplace Fallback</label>
                        <select name="default_customer_id" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">— pilih customer —</option>
                            @foreach($marketplaceCustomers as $c)
                                <option value="{{ $c->id }}" {{ (int) old('default_customer_id', $setting->default_customer_id) === $c->id ? 'selected' : '' }}>
                                    {{ $c->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Dipakai bila nama toko Jubelio belum dipetakan ke customer marketplace tertentu.</p>
                    </div>
                </div>
            </div>

            {{-- ===== Webhook ===== --}}
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-3">Webhook (opsional, butuh URL publik)</h3>
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Webhook Secret Key</label>
                        <input type="text" name="webhook_secret" value="{{ old('webhook_secret', $setting->webhook_secret) }}"
                               class="w-full border rounded px-3 py-2 font-mono text-xs" placeholder="Secret untuk verifikasi signature">
                        <p class="text-xs text-gray-400 mt-1">
                            Set di Jubelio (Pengaturan → Developer → Webhook) dan isi sama di sini. Callback URL:
                            <code class="bg-gray-100 px-1">{{ url('/jubelio/webhook/salesorder') }}</code>,
                            <code class="bg-gray-100 px-1">/salesreturn</code>, <code class="bg-gray-100 px-1">/stock</code>.
                            Saat di localhost, andalkan sinkron otomatis terjadwal (cron) — webhook hanya jalan jika ERP dapat diakses publik.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-center border-t pt-4">
                <span class="text-xs {{ $setting->hasValidToken() ? 'text-green-600' : 'text-gray-400' }}">
                    Token: {{ $setting->hasValidToken() ? 'aktif s/d ' . $setting->token_expires_at->format('d M H:i') : 'belum / kedaluwarsa' }}
                </span>
                <div class="flex gap-2">
                    <button formaction="{{ route('settings.jubelio.test') }}" formmethod="POST"
                            class="border border-blue-600 text-blue-600 px-4 py-2 rounded font-semibold text-sm hover:bg-blue-50">Test Koneksi</button>
                    <button class="bg-blue-600 text-white px-5 py-2 rounded font-semibold">Simpan</button>
                </div>
            </div>
        </form>
    </div>

    {{-- ===== Pemetaan Toko → Customer Marketplace ===== --}}
    <div class="bg-white shadow rounded-lg border p-6 mt-5">
        <h3 class="text-base font-bold text-gray-800 mb-1">Pemetaan Toko Jubelio → Customer &amp; Akuntansi Marketplace</h3>
        <p class="text-sm text-gray-500 mb-5">
            Petakan tiap toko/channel Jubelio ke customer marketplace, lalu atur biaya admin &amp; akunnya.
            Toko yang belum dipetakan memakai <b>Customer Marketplace Fallback</b> di atas.
        </p>

        {{-- Langkah 1: petakan toko → customer (tambah satu per satu, jadi baris di Langkah 2) --}}
        <form method="POST" action="{{ route('settings.jubelio.channel-map.store') }}" class="mb-6 bg-slate-50 border rounded-lg p-5">
            @csrf
            <div class="flex items-center justify-between mb-3">
                <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">Langkah 1 · Petakan Toko → Customer</div>
                <span class="text-[11px] text-slate-400">Tambah satu per satu — bisa banyak toko</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-4 items-end">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Nama Toko / Channel (di Jubelio)</label>
                        <div class="flex gap-2 items-center">
                            <select name="store" id="jbStore" required class="flex-1 border rounded px-3 py-2 text-sm">
                                <option value="" disabled selected>— pilih / ketik toko —</option>
                            </select>
                            <button type="button" id="jbFindStore" class="shrink-0 px-3 py-2 border border-blue-600 text-blue-600 rounded text-xs font-semibold hover:bg-blue-50 whitespace-nowrap disabled:opacity-60">Cari Toko</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Customer Marketplace ERP</label>
                        <select name="customer_id" id="jbCustomer" required class="w-full border rounded px-3 py-2 text-sm">
                            <option value="" disabled selected>— pilih / ketik nama baru —</option>
                            @foreach($marketplaceCustomers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <button class="shrink-0 h-[38px] bg-blue-600 text-white px-5 rounded font-semibold text-sm hover:bg-blue-700 whitespace-nowrap">+ Tambah Toko</button>
            </div>
            <p class="text-xs text-gray-400 mt-3" id="jbStoreHint">Klik "Cari Toko" lalu <b>pilih toko dari daftar</b> — pemetaan dikunci ke <b>store_id</b> Jubelio (unik per toko, supaya Shopee &amp; Tokopedia yang namanya sama tidak tertukar). Customer baru dibuat otomatis. Potongan &amp; akun diisi di Langkah 2.</p>
        </form>

        {{-- Langkah 2: potongan & akun per baris --}}
        <div class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">
            Langkah 2 · Potongan &amp; Akun (per toko)
            @if(!$channelMaps->isEmpty())<span class="text-slate-400 font-normal normal-case">— {{ $channelMaps->count() }} toko</span>@endif
        </div>
        @if($channelMaps->isEmpty())
            <div class="text-xs text-gray-400 border border-dashed rounded-lg py-6 text-center">Belum ada toko. Tambahkan di Langkah 1 — tiap toko muncul sebagai baris di sini.</div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-400 border-b">
                        <th class="py-2">Toko Jubelio</th>
                        <th class="py-2">Customer ERP</th>
                        <th class="py-2">Biaya</th>
                        <th class="py-2">Akun (Hold / Fee / Wallet)</th>
                        <th class="py-2 w-28 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($channelMaps as $map)
                        @php $cfg = $map->customer->marketplace ?? null; @endphp
                        <tr class="border-b border-gray-50">
                            <td class="py-2 font-medium text-gray-700">{{ ctype_digit((string) $map->store) ? 'Toko #' . $map->store : $map->store }}</td>
                            <td class="py-2 text-gray-600">{{ $map->customer->name ?? '—' }}</td>
                            <td class="py-2 text-gray-600">
                                @if($cfg)
                                    {{ rtrim(rtrim(number_format($cfg->admin_fee_percent, 2, '.', ''), '0'), '.') }}%
                                    + Rp{{ number_format($cfg->admin_fee_fixed, 0, ',', '.') }}
                                @else
                                    <span class="text-amber-500">belum diatur</span>
                                @endif
                            </td>
                            <td class="py-2 text-xs text-gray-500">
                                @if($cfg)
                                    {{ $cfg->holdAccount->code ?? '?' }} / {{ $cfg->feeAccount->code ?? '?' }} / {{ $cfg->walletAccount->code ?? '?' }}
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <button type="button" class="text-xs text-blue-600 hover:underline jb-toggle-cfg" data-target="cfg-{{ $map->id }}">
                                    {{ $cfg ? 'Ubah Akun' : 'Atur Akun' }}
                                </button>
                                <form method="POST" action="{{ route('settings.jubelio.channel-map.destroy', $map->id) }}"
                                      class="inline" onsubmit="return confirm('Hapus pemetaan ini?')">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-red-500 hover:underline ml-2">Hapus</button>
                                </form>
                            </td>
                        </tr>
                        {{-- Editor inline potongan & akun (tersembunyi sampai "Atur/Ubah Akun") --}}
                        <tr id="cfg-{{ $map->id }}" class="hidden bg-slate-50/70">
                            <td colspan="5" class="px-2 py-3">
                                <form method="POST" action="{{ route('settings.jubelio.marketplace-config.store') }}"
                                      class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $map->customer_id }}">
                                    <div class="w-24">
                                        <label class="block text-[11px] font-semibold text-gray-500 mb-1">Biaya (%)</label>
                                        <input type="number" step="0.01" min="0" name="admin_fee_percent" value="{{ $cfg->admin_fee_percent ?? 0 }}" class="w-full border rounded px-2 py-1.5 text-sm">
                                    </div>
                                    <div class="w-28">
                                        <label class="block text-[11px] font-semibold text-gray-500 mb-1">Biaya (Rp)</label>
                                        <input type="number" step="1" min="0" name="admin_fee_fixed" value="{{ $cfg->admin_fee_fixed ?? 0 }}" class="w-full border rounded px-2 py-1.5 text-sm">
                                    </div>
                                    <div class="min-w-[200px]">
                                        @include('erp.fixed-assets.categories._account_picker', [
                                            'name' => 'account_receivable_hold_id',
                                            'label' => 'Akun Hold (Aset)',
                                            'required' => true,
                                            'value' => $cfg->account_receivable_hold_id ?? null,
                                            'displayValue' => $accLabel($cfg->account_receivable_hold_id ?? null),
                                            'searchTypes' => 'asset',
                                        ])
                                    </div>
                                    <div class="min-w-[200px]">
                                        @include('erp.fixed-assets.categories._account_picker', [
                                            'name' => 'account_fee_id',
                                            'label' => 'Akun Fee (Beban)',
                                            'required' => true,
                                            'value' => $cfg->account_fee_id ?? null,
                                            'displayValue' => $accLabel($cfg->account_fee_id ?? null),
                                            'searchTypes' => 'expense',
                                        ])
                                    </div>
                                    <div class="min-w-[200px]">
                                        @include('erp.fixed-assets.categories._account_picker', [
                                            'name' => 'account_wallet_id',
                                            'label' => 'Akun Wallet (Aset)',
                                            'required' => true,
                                            'value' => $cfg->account_wallet_id ?? null,
                                            'displayValue' => $accLabel($cfg->account_wallet_id ?? null),
                                            'searchTypes' => 'asset',
                                        ])
                                    </div>
                                    <button class="bg-emerald-600 text-white px-3 py-1.5 rounded text-sm font-semibold hover:bg-emerald-700">Simpan</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('btnFindLocation')?.addEventListener('click', async function () {
    const btn = this;
    const select = document.getElementById('jubelioLocation');
    const hint = document.getElementById('jubelioLocationHint');
    const current = select.dataset.current;
    btn.disabled = true; const label = btn.textContent; btn.textContent = 'Mencari…';
    try {
        const res = await fetch('{{ route('settings.jubelio.locations') }}', { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data.success) { hint.textContent = data.message || 'Gagal mengambil lokasi.'; hint.className = 'text-xs text-red-500 mt-1'; return; }
        const locs = data.locations || [];
        select.innerHTML = '';
        if (!locs.length) { select.innerHTML = '<option value="">(tidak ada lokasi)</option>'; hint.textContent = 'Jubelio tidak mengembalikan lokasi apa pun.'; hint.className = 'text-xs text-red-500 mt-1'; return; }
        locs.forEach(l => {
            const opt = document.createElement('option');
            opt.value = l.id; opt.textContent = l.name + ' (id ' + l.id + ')';
            if (String(l.id) === String(current)) opt.selected = true;
            select.appendChild(opt);
        });
        if (locs.length === 1) {
            select.value = locs[0].id;
            hint.textContent = 'Hanya 1 lokasi: "' + locs[0].name + '" terpilih otomatis. Jangan lupa Simpan.';
        } else {
            hint.textContent = locs.length + ' lokasi ditemukan — pilih salah satu lalu Simpan.';
        }
        hint.className = 'text-xs text-green-600 mt-1';
    } catch (e) {
        hint.textContent = 'Error: ' + e.message; hint.className = 'text-xs text-red-500 mt-1';
    } finally {
        btn.disabled = false; btn.textContent = label;
    }
});
</script>

<script>
// Langkah 1 (toko→customer): live-search TomSelect + Cari Toko. Langkah 2: toggle editor akun.
document.addEventListener('DOMContentLoaded', function () {
    const ts = {};
    ['jbStore', 'jbCustomer'].forEach(function (id) {
        const el = document.getElementById(id);
        if (!el) return;
        const opts = { create: false, maxItems: 1, dropdownParent: 'body',
            placeholder: (el.options[0] && !el.options[0].value) ? el.options[0].text : 'Cari…' };
        if (id === 'jbStore') {
            // Toko: nilai = nama toko apa adanya (boleh ketik baru).
            opts.create = (input) => ({ value: input.trim(), text: input.trim() });
            opts.createOnBlur = true;
        } else {
            // Customer: ketik nama baru → "new:<nama>" (dibuat saat Simpan).
            opts.create = (input) => ({ value: 'new:' + input.trim(), text: input.trim() + ' (buat baru)' });
            opts.createOnBlur = true;
        }
        ts[id] = new TomSelect(el, opts);
    });

    // Saat toko dipilih & customer masih kosong → tawarkan customer = NAMA toko (bukan store_id).
    ts['jbStore']?.on('change', function (val) {
        const cust = ts['jbCustomer'];
        if (!val || !cust || cust.getValue()) return;
        const opt = ts['jbStore'].options[val] || {};
        const nm = opt.name || opt.text || val;
        cust.addOption({ value: 'new:' + nm, text: nm + ' (buat baru)' });
        cust.refreshOptions(false);
        cust.setValue('new:' + nm, true);
    });

    // Tombol "Cari Toko": tarik daftar toko Jubelio → jadi opsi di jbStore.
    document.getElementById('jbFindStore')?.addEventListener('click', async function () {
        const btn = this, hint = document.getElementById('jbStoreHint'), store = ts['jbStore'];
        if (!store) return;
        btn.disabled = true; const label = btn.textContent; btn.textContent = 'Mencari…';
        try {
            const res = await fetch('{{ route('settings.marketplace.stores') }}', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (!data.success) { hint.textContent = data.message || 'Gagal mengambil toko.'; hint.className = 'text-xs text-red-500'; return; }
            const stores = data.stores || [];
            // value = store_id (kunci routing UNIK; store_name bisa bentrok antar channel).
            // label: nama + marketplace + #store_id biar mudah dibedakan.
            stores.forEach(s => store.addOption({
                value: String(s.id),
                name: s.name,
                text: (s.channel ? (s.name + ' — ' + s.channel) : s.name) + ' (#' + s.id + ')',
            }));
            store.refreshOptions(false);
            if (!stores.length) { hint.textContent = 'Jubelio tidak mengembalikan toko apa pun.'; hint.className = 'text-xs text-red-500'; return; }
            hint.textContent = stores.length + ' toko Jubelio dimuat — pilih satu lalu Tambah / Perbarui.';
            hint.className = 'text-xs text-green-600';
            store.open();
        } catch (e) {
            hint.textContent = 'Error: ' + e.message; hint.className = 'text-xs text-red-500';
        } finally {
            btn.disabled = false; btn.textContent = label;
        }
    });

    // Langkah 2: buka/tutup editor potongan & akun per baris.
    document.querySelectorAll('.jb-toggle-cfg').forEach(function (b) {
        b.addEventListener('click', function () {
            const row = document.getElementById(b.dataset.target);
            if (row) row.classList.toggle('hidden');
        });
    });
});
</script>

{{-- Live-search akun (Akun Hold / Fee / Wallet) — pola sama dgn _account_picker --}}
<style>
.acc-pick { position: relative; }
.acc-pick-dropdown { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #d1d5db; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 30; max-height: 240px; overflow-y: auto; display: none; }
.acc-pick-dropdown.show { display: block; }
.acc-pick-dropdown .item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
.acc-pick-dropdown .item:hover { background: #eff6ff; }
.acc-pick-dropdown .item .code { font-family: monospace; font-size: 11px; color: #2563eb; font-weight: bold; }
.acc-pick-dropdown .item .name { color: #1f2937; }
.acc-pick-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #9ca3af; font-size: 18px; }
.acc-pick-clear:hover { color: #ef4444; }
</style>
<script>
(function () {
    const SEARCH_URL = "{{ route('accounts.search') }}";
    document.querySelectorAll('.acc-pick').forEach(function (wrap) {
        const input = wrap.querySelector('.acc-pick-input');
        const hidden = wrap.querySelector('.acc-pick-id');
        const dropdown = wrap.querySelector('.acc-pick-dropdown');
        const clearBtn = wrap.querySelector('.acc-pick-clear');
        const types = (wrap.dataset.types || '').split(',').map(s => s.trim()).filter(Boolean);
        let timer;

        async function doSearch(q) {
            const params = new URLSearchParams();
            if (q) params.append('q', q);
            types.forEach(t => params.append('types[]', t));
            try {
                const res = await fetch(`${SEARCH_URL}?${params.toString()}`);
                const list = await res.json();
                renderResults(list);
            } catch (e) { console.error(e); }
        }

        function renderResults(list) {
            dropdown.innerHTML = '';
            if (!list.length) {
                dropdown.innerHTML = '<div class="item text-gray-400">Tidak ada akun cocok.</div>';
            } else {
                list.forEach(a => {
                    const div = document.createElement('div');
                    div.className = 'item';
                    div.innerHTML = `<div class="code">${a.code}</div><div class="name">${a.name}</div>`;
                    div.addEventListener('click', () => {
                        hidden.value = a.id;
                        input.value = `${a.code} — ${a.name}`;
                        dropdown.classList.remove('show');
                    });
                    dropdown.appendChild(div);
                });
            }
            dropdown.classList.add('show');
        }

        input.addEventListener('input', () => {
            hidden.value = '';
            clearTimeout(timer);
            timer = setTimeout(() => doSearch(input.value.trim()), 200);
        });
        input.addEventListener('focus', () => { doSearch(input.value.trim()); });
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) dropdown.classList.remove('show');
        });
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                hidden.value = '';
                input.value = '';
                input.focus();
            });
        }
    });
})();
</script>
@endpush
