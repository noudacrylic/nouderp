@extends('layouts.erp')

@section('content')
@php
    $warna = [
        'so_tidak_ada' => 'bg-red-100 text-red-700', 'beda_status' => 'bg-red-100 text-red-700',
        'beda_barang' => 'bg-orange-100 text-orange-700', 'sku_belum_dipeta' => 'bg-amber-100 text-amber-800',
        'beda_harga' => 'bg-yellow-100 text-yellow-800', 'belum_dibayar' => 'bg-gray-100 text-gray-600',
        'cocok' => 'bg-green-100 text-green-700',
    ];
    $total = $hitung->sum();
@endphp
<div class="max-w-6xl mx-auto">
    <a href="{{ route('settings.integrations.shopee.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Shopee Open Platform</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h2 class="text-xl font-bold text-gray-800 mb-1">Pesanan Bayangan Shopee</h2>
                <p class="text-sm text-gray-500 max-w-2xl">
                    Pesanan ditarik langsung dari Shopee (tiap 15 menit) lalu dibandingkan dengan SO yang dibuat Jubelio.
                    <b>Tidak menulis apa pun</b> — tujuannya membuktikan jalur langsung sama dengan Jubelio sebelum Jubelio dimatikan.
                </p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="{{ route('settings.integrations.shopee.peta-sku') }}" class="border border-orange-300 text-orange-700 hover:bg-orange-50 px-3 py-2 rounded text-sm font-semibold">Peta SKU</a>
                <form method="POST" action="{{ route('settings.integrations.shopee.pesanan.banding-ulang') }}">@csrf
                    <button class="border border-blue-300 text-blue-700 hover:bg-blue-50 px-3 py-2 rounded text-sm font-semibold">Bandingkan ulang</button>
                </form>
                <form method="POST" action="{{ route('settings.integrations.shopee.pesanan.tarik') }}" class="flex gap-1">@csrf
                    <select name="hari" class="border rounded px-2 py-2 text-sm">
                        @foreach ([1, 3, 7, 15, 30] as $h)<option value="{{ $h }}" @selected($h === 3)>{{ $h }} hari</option>@endforeach
                    </select>
                    <button class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded text-sm font-semibold" @disabled(!$shop)>Tarik sekarang</button>
                </form>
            </div>
        </div>

        @unless ($shop)
            <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Belum ada toko terhubung untuk lingkungan aktif.</div>
        @endunless

        <form method="GET" class="mt-5 flex flex-wrap items-center gap-2 text-sm">
            <a href="{{ request()->fullUrlWithQuery(['hasil' => null, 'page' => null]) }}"
               class="px-3 py-1.5 rounded-full border text-xs font-semibold {{ !$hasil ? 'bg-gray-800 text-white border-gray-800' : 'text-gray-600 hover:bg-gray-50' }}">Semua <span class="opacity-70">{{ number_format($total) }}</span></a>
            @foreach (\App\Modules\Marketplace\Shopee\Models\ShopeeOrder::HASIL as $k => $label)
                @if ($hitung[$k] ?? 0)
                    <a href="{{ request()->fullUrlWithQuery(['hasil' => $k, 'page' => null]) }}"
                       class="px-3 py-1.5 rounded-full border text-xs font-semibold {{ $hasil === $k ? 'ring-2 ring-gray-400' : '' }} {{ $warna[$k] }}">{{ $label }} {{ number_format($hitung[$k]) }}</a>
                @endif
            @endforeach
            <input type="hidden" name="hasil" value="{{ $hasil }}">
            <input type="date" name="dari" value="{{ $dari->format('Y-m-d') }}" class="border rounded px-2 py-1.5 text-sm ml-2">
            <span class="text-gray-400">s/d</span>
            <input type="date" name="sampai" value="{{ $sampai->format('Y-m-d') }}" class="border rounded px-2 py-1.5 text-sm">
            <input type="text" name="cari" value="{{ $cari }}" placeholder="No. pesanan" class="border rounded px-3 py-1.5 text-sm">
            <button class="border rounded px-3 py-1.5 text-sm">Terapkan</button>
            <div class="ml-auto">@include('erp._partials.per-page-select')</div>
        </form>

        <div class="overflow-x-auto mt-3">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="text-left px-3 py-2">Pesanan Shopee</th>
                        <th class="text-left px-3 py-2">Barang (Shopee)</th>
                        <th class="text-right px-3 py-2">Total</th>
                        <th class="text-left px-3 py-2">SO Jubelio</th>
                        <th class="text-left px-3 py-2">Hasil</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr class="border-t align-top">
                            <td class="px-3 py-2 whitespace-nowrap">
                                <div class="font-mono text-xs font-semibold">{{ $o->order_sn }}</div>
                                <div class="text-xs text-gray-500">{{ $o->dibuat_shopee_at?->format('d/m/Y H:i') }}</div>
                                <div class="text-[11px] text-gray-400">{{ $o->status }}{{ $o->kurir ? ' · ' . $o->kurir : '' }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @foreach ($o->items as $it)
                                    <div>
                                        {{ $it->qty }}× <span class="font-mono {{ $it->product_id ? '' : 'text-red-600' }}">{{ $it->product?->sku ?? ($it->sku ?: '—') }}</span>
                                        <span class="text-gray-500">{{ \Illuminate\Support\Str::limit($it->nama . ($it->nama_model ? ' — ' . $it->nama_model : ''), 60) }}</span>
                                        <span class="text-gray-400">@ {{ number_format((float) $it->harga_diskon, 0, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">{{ $o->total !== null ? number_format($o->total, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs">
                                @if ($o->salesOrder)
                                    <a href="{{ route('sales.orders.show', $o->salesOrder->id) }}" class="text-blue-600 hover:underline font-mono">{{ $o->salesOrder->order_number }}</a>
                                    <div class="text-gray-400">{{ $o->salesOrder->status }}</div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded {{ $warna[$o->hasil_banding] ?? 'bg-gray-100' }}">{{ \App\Modules\Marketplace\Shopee\Models\ShopeeOrder::HASIL[$o->hasil_banding] ?? $o->hasil_banding }}</span>
                                @if ($o->catatan_banding)<div class="text-xs text-gray-500 mt-1 max-w-xs">{{ $o->catatan_banding }}</div>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada pesanan pada rentang ini — klik "Tarik sekarang".</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
