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
                    koneksi toko + <a href="{{ route('finance.cash-bank.settlements.mutasi-shopee.index') }}" class="text-blue-600 hover:underline">Mutasi Saldo Shopee</a>.
                </p>
            </div>
            <span class="rounded px-2 py-1 text-xs font-bold {{ $env === 'live' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                {{ $env === 'live' ? 'LIVE' : 'TEST (sandbox)' }}
            </span>
        </div>

        {{-- Kunci app --}}
        <div class="mt-5 rounded border {{ $siap ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' }} p-4 text-sm">
            @if ($siap)
                <div class="font-semibold text-green-800">✅ Kunci app terbaca dari .env server</div>
                <div class="text-xs text-green-700 mt-1">Host API: <span class="font-mono">{{ $host }}</span></div>
            @else
                <div class="font-semibold text-amber-800">Kunci app belum diisi</div>
                <ol class="text-xs text-amber-800 mt-2 list-decimal ml-4 space-y-1">
                    <li>Buka Shopee Open Platform › App Management › app <b>Seller In House System</b>, salin <b>Partner ID</b> &amp; <b>Partner Key</b> (Test dulu).</li>
                    <li>Tambahkan ke <span class="font-mono">.env</span> server (jangan dikirim lewat chat):
                        <pre class="mt-1 bg-white border rounded p-2 font-mono text-[11px] text-gray-700">SHOPEE_PARTNER_ID=...
SHOPEE_PARTNER_KEY=...
SHOPEE_ENV=test</pre>
                    </li>
                    <li>Jalankan <span class="font-mono">php artisan config:cache</span> (atau <span class="font-mono">optimize</span>), lalu muat ulang halaman ini.</li>
                </ol>
            @endif
            <div class="text-xs text-gray-500 mt-3">
                Redirect URL Domain di app Shopee: <span class="font-mono">{{ parse_url($callback, PHP_URL_SCHEME) . '://' . parse_url($callback, PHP_URL_HOST) }}</span>
                · alamat kembali lengkap: <span class="font-mono">{{ $callback }}</span>
            </div>
        </div>

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
