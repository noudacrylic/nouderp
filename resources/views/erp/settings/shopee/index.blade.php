@extends('layouts.erp')

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('settings.integrations.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Integrasi</a>

    <div class="bg-white shadow rounded-lg border p-6 mb-4">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h2 class="text-xl font-bold text-gray-800 mb-1">Shopee Open Platform</h2>
                <p class="text-sm text-gray-500 max-w-2xl">
                    Koneksi langsung ERP ke Shopee (API v2), bertahap menggantikan Jubelio. Tahap sekarang:
                    koneksi toko + <a href="{{ route('finance.cash-bank.settlements.mutasi-shopee.index') }}" class="text-blue-600 hover:underline">Mutasi Saldo Shopee</a>
                    + bayangan (baca saja): <a href="{{ route('settings.integrations.shopee.peta-sku') }}" class="text-blue-600 hover:underline">Peta SKU</a>
                    · <a href="{{ route('settings.integrations.shopee.pesanan') }}" class="text-blue-600 hover:underline">Pesanan Bayangan</a>.
                </p>
            </div>
            <span class="rounded px-2 py-1 text-xs font-bold {{ $env === 'live' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                {{ $env === 'live' ? 'LIVE' : 'TEST (sandbox)' }}
            </span>
        </div>

        @php($sisaKunci = $setting->sisaHariKunciLive())
        @if ($sisaKunci !== null && $sisaKunci <= 30)
            <div class="mt-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                ⚠️ Live API Partner Key {{ $sisaKunci < 0 ? 'SUDAH kedaluwarsa sejak' : ($sisaKunci === 0 ? 'kedaluwarsa HARI INI' : 'kedaluwarsa ' . $sisaKunci . ' hari lagi —') }}
                {{ $setting->live_key_expires_at->format('d/m/Y') }}. Perbarui kunci di Shopee Open Platform › App, lalu isi kunci & tanggal barunya di bawah.
            </div>
        @endif

        {{-- Kunci app --}}
        <form method="POST" action="{{ route('settings.integrations.shopee.update') }}" class="mt-5 rounded border {{ $siap ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' }} p-4 text-sm">
            @csrf @method('PUT')
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="font-semibold {{ $siap ? 'text-green-800' : 'text-amber-800' }}">
                    {{ $siap ? '✅ Kunci app ' . strtoupper($env) . ' siap' : 'Kunci app ' . strtoupper($env) . ' belum diisi' }}
                </div>
                <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                    Lingkungan aktif
                    <select name="env" class="border rounded px-2 py-1 text-sm">
                        <option value="test" @selected(old('env', $setting->envAktif()) === 'test')>Test (sandbox)</option>
                        <option value="live" @selected(old('env', $setting->envAktif()) === 'live')>Live</option>
                    </select>
                </label>
            </div>
            <p class="text-xs text-gray-600 mt-1">
                Salin dari Shopee Open Platform › App Management › app <b>Seller In House System</b>. Kunci Test hanya untuk toko sandbox (Test Account), kunci Live untuk toko asli.
                Host API aktif: <span class="font-mono">{{ $host }}</span>
            </p>

            <div class="grid md:grid-cols-2 gap-4 mt-3">
                @foreach (['test' => 'Test', 'live' => 'Live'] as $e => $label)
                    <div class="bg-white border rounded p-3 {{ $env === $e ? 'ring-2 ring-orange-300' : '' }}">
                        <div class="font-semibold text-gray-800 mb-2">{{ $label }}
                            @if ($env === $e)<span class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-orange-100 text-orange-700">AKTIF</span>@endif
                        </div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Partner ID</label>
                        <input type="text" inputmode="numeric" name="{{ $e }}_partner_id" value="{{ old($e . '_partner_id', $setting->{$e . '_partner_id'}) }}"
                               class="w-full border rounded px-3 py-2 text-sm font-mono" placeholder="mis. 1250096">
                        <label class="block text-xs font-semibold text-gray-700 mt-2 mb-1">Partner Key</label>
                        <input type="password" name="{{ $e }}_partner_key" value="" autocomplete="new-password"
                               class="w-full border rounded px-3 py-2 text-sm font-mono" placeholder="{{ $setting->safeAttr($e . '_partner_key') ? '•••••••• (tersimpan)' : 'Partner Key ' . $label }}">
                        <p class="text-[11px] text-gray-400 mt-1">Kosongkan bila tidak ingin mengubah kunci tersimpan.</p>
                        @if ($setting->safeAttr($e . '_partner_key'))
                            <label class="inline-flex items-center gap-1 text-[11px] text-red-600 mt-1">
                                <input type="checkbox" name="hapus_{{ $e }}_key" value="1"> Hapus kunci tersimpan
                            </label>
                        @elseif ($setting->siap($e))
                            <p class="text-[11px] text-amber-700 mt-1">Saat ini memakai cadangan dari .env server.</p>
                        @endif
                        @if ($e === 'live')
                            <label class="block text-xs font-semibold text-gray-700 mt-2 mb-1">Kunci Live berlaku s/d</label>
                            <input type="date" name="live_key_expires_at" value="{{ old('live_key_expires_at', $setting->live_key_expires_at?->format('Y-m-d')) }}"
                                   class="w-full border rounded px-3 py-2 text-sm">
                            <p class="text-[11px] text-gray-400 mt-1">Lihat "Live API Partner Key Expire Time" di Console. Pengingat Telegram dikirim H-30, H-14, H-7, H-3 lalu tiap hari.</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-between gap-3 flex-wrap mt-3">
                <div class="text-xs text-gray-500">
                    Redirect URL Domain di app Shopee: <span class="font-mono">{{ parse_url($callback, PHP_URL_SCHEME) . '://' . parse_url($callback, PHP_URL_HOST) }}</span>
                    · alamat kembali lengkap: <span class="font-mono">{{ $callback }}</span>
                </div>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
            </div>
        </form>
        @if ($siap)
            <form method="POST" action="{{ route('settings.integrations.shopee.cek-kunci') }}" class="mt-2 text-right">@csrf
                <button class="border border-orange-300 text-orange-700 hover:bg-orange-50 px-3 py-1.5 rounded text-xs font-semibold">Cek kunci {{ strtoupper($env) }} ke Shopee</button>
            </form>
        @endif

        {{-- Toko --}}
        <div class="mt-6 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Toko terhubung</h3>
            @if ($siap)
                <a href="{{ route('settings.integrations.shopee.hubungkan') }}" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded text-sm font-semibold">+ Hubungkan Toko</a>
            @endif
        </div>
        <div class="mt-2 divide-y border rounded">
            @forelse ($shops as $s)
                <div class="p-3 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <div>
                        <div class="font-semibold text-gray-800">{{ $s->nama ?: 'Toko ' . $s->shop_id }}
                            <span class="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded {{ $s->env === 'live' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ strtoupper($s->env) }}</span>
                        </div>
                        <div class="text-xs text-gray-500">
                            shop_id {{ $s->shop_id }} · dihubungkan {{ $s->penghubung?->name ?? '—' }}{{ $s->terhubung_at ? ', ' . $s->terhubung_at->format('d/m/Y H:i') : '' }}
                        </div>
                        <div class="text-xs {{ $s->perluHubungkanUlang() ? 'text-red-600 font-semibold' : 'text-gray-500' }}">
                            @if ($s->perluHubungkanUlang())
                                Izin kedaluwarsa — hubungkan ulang.
                            @else
                                Token akses s/d {{ $s->access_expires_at?->format('d/m H:i') }} · izin s/d {{ $s->refresh_expires_at?->format('d/m/Y') }} (diperpanjang otomatis tiap jam)
                            @endif
                        </div>
                        @if ($s->galat_terakhir)<div class="text-xs text-red-600">Galat terakhir: {{ $s->galat_terakhir }}</div>@endif
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('settings.integrations.shopee.uji', $s->id) }}">@csrf
                            <button class="border border-blue-300 text-blue-700 hover:bg-blue-50 px-2 py-1 rounded text-xs font-semibold">Uji koneksi</button>
                        </form>
                        <form method="POST" action="{{ route('settings.integrations.shopee.putuskan', $s->id) }}" onsubmit="return confirm('Lepas toko ini dari ERP?')">@csrf @method('DELETE')
                            <button class="border border-red-200 text-red-600 hover:bg-red-50 px-2 py-1 rounded text-xs">Lepas</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="p-4 text-sm text-gray-400 text-center">Belum ada toko terhubung.</div>
            @endforelse
        </div>

        {{-- Chat Shopee → CRM (Tahap A, baca saja) --}}
        @php($tokoAktif = $shops->firstWhere('env', $env))
        <div class="mt-6 rounded border border-orange-200 bg-orange-50 p-4 text-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-gray-800">Chat Shopee → CRM</h3>
                    <p class="text-xs text-gray-600 mt-1 max-w-xl">
                        Chat Shopee disalin ke <a href="{{ route('crm.inbox.index') }}" class="text-blue-600 hover:underline">Inbox CRM</a>
                        seketika lewat Live Push, dan tiap menit sebagai cadangan. Bisa dibalas (teks &amp; gambar) dari CRM.
                        Percakapan yang hanya dilayani AI Shopee disembunyikan sampai pembeli meminta penjual.
                    </p>
                    <p class="text-xs text-gray-600 mt-1">
                        Live Push URL (isi di Console › App › Push Mechanism, centang push chat/webchat):
                        <code class="bg-gray-100 px-1 rounded select-all">{{ $pushUrl }}</code>
                    </p>
                    <p class="text-xs text-gray-600 mt-1">
                        Push terakhir:
                        @if ($pushTerakhir)
                            <b>{{ $pushTerakhir->created_at?->format('d/m/Y H:i:s') }}</b> ({{ $pushTerakhir->path }})
                            @if ($pushTerakhir->error)
                                <span class="text-red-600 font-semibold">— {{ $pushTerakhir->error === 'tanda_tangan_salah' ? 'tanda tangan tidak cocok, diabaikan' : $pushTerakhir->error }}</span>
                            @endif
                        @else
                            <b>belum pernah diterima</b>
                        @endif
                    </p>
                    <p class="text-xs text-gray-600 mt-1">
                        Tampil di CRM: <b>{{ number_format($chat['tampil'], 0, ',', '.') }}</b> percakapan ·
                        disembunyikan (AI saja): <b>{{ number_format($chat['tersembunyi'], 0, ',', '.') }}</b> ·
                        riwayat lama:
                        @if ($tokoAktif?->chat_riwayat_selesai_at)
                            <b>selesai</b> {{ $tokoAktif->chat_riwayat_selesai_at->format('d/m/Y H:i') }}
                        @elseif ($tokoAktif?->chat_riwayat_kursor)
                            <b>berjalan</b> — sudah sampai {{ date('d/m/Y', (int) substr($tokoAktif->chat_riwayat_kursor, 0, -9)) }}
                        @else
                            <b>belum ditarik</b>
                        @endif
                    </p>
                </div>
                <form method="POST" action="{{ route('settings.integrations.shopee.chat') }}" class="flex items-end gap-2">
                    @csrf @method('PUT')
                    <label class="block text-xs font-semibold text-gray-700">Pemilik awal chat Shopee
                        <select name="chat_owner_user_id" class="mt-1 block border rounded px-2 py-1.5 text-sm bg-white">
                            <option value="">— Belum dioper —</option>
                            @foreach ($agenChat as $u)
                                <option value="{{ $u->id }}" @selected($setting->chat_owner_user_id === $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-semibold">Simpan</button>
                </form>
            </div>
        </div>
    </div>

    {{-- Log --}}
    <div class="bg-white shadow rounded-lg border p-6">
        <h3 class="font-semibold text-gray-800 mb-2">Log panggilan API <span class="text-xs font-normal text-gray-400">30 terakhir · tanpa token/kunci</span></h3>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr><th class="text-left px-2 py-1">Waktu</th><th class="text-left px-2 py-1">Panggilan</th><th class="text-left px-2 py-1">Hasil</th><th class="text-right px-2 py-1">ms</th></tr>
                </thead>
                <tbody>
                    @forelse ($logs as $l)
                        <tr class="border-t">
                            <td class="px-2 py-1 whitespace-nowrap text-gray-500">{{ $l->created_at?->format('d/m H:i:s') }}</td>
                            <td class="px-2 py-1 font-mono">{{ $l->method }} {{ $l->path }}{{ $l->shop_id ? ' · ' . $l->shop_id : '' }}</td>
                            <td class="px-2 py-1 {{ $l->berhasil() ? 'text-green-700' : 'text-red-600' }}">
                                {{ $l->http_status ?? '—' }} {{ $l->error }} {{ $l->message ? '— ' . \Illuminate\Support\Str::limit($l->message, 120) : '' }}
                                @if ($l->request_id)<span class="text-gray-400">· {{ $l->request_id }}</span>@endif
                            </td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ $l->durasi_ms }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-2 py-4 text-center text-gray-400">Belum ada panggilan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
