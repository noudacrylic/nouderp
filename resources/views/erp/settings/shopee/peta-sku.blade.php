@extends('layouts.erp')

@section('content')
<div class="max-w-6xl mx-auto">
    <a href="{{ route('settings.integrations.shopee.index') }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← Shopee Open Platform</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h2 class="text-xl font-bold text-gray-800 mb-1">Peta SKU Shopee</h2>
                <p class="text-sm text-gray-500 max-w-2xl">
                    Tiap varian di toko Shopee{{ $shop ? ' ' . ($shop->nama ?: $shop->shop_id) : '' }} dipasangkan ke produk ERP.
                    Otomatis bila SKU-nya sama persis; sisanya isi SKU ERP manual — peta manual tidak ditimpa tarikan berikutnya.
                    Katalog ditarik otomatis tiap pagi.
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('settings.integrations.shopee.pesanan') }}" class="border border-orange-300 text-orange-700 hover:bg-orange-50 px-3 py-2 rounded text-sm font-semibold">Pesanan Bayangan</a>
                <form method="POST" action="{{ route('settings.integrations.shopee.peta-sku.tarik') }}">@csrf
                    <button class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded text-sm font-semibold" @disabled(!$shop)>Tarik katalog sekarang</button>
                </form>
            </div>
        </div>

        @unless ($shop)
            <div class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Belum ada toko terhubung untuk lingkungan aktif.</div>
        @endunless

        <form method="GET" class="mt-5 flex flex-wrap items-center gap-2 text-sm">
            @foreach (['belum' => 'Belum dipetakan', 'manual' => 'Peta manual', 'semua' => 'Semua'] as $k => $label)
                <a href="{{ request()->fullUrlWithQuery(['filter' => $k, 'page' => null]) }}"
                   class="px-3 py-1.5 rounded-full border text-xs font-semibold {{ $filter === $k ? 'bg-gray-800 text-white border-gray-800' : 'text-gray-600 hover:bg-gray-50' }}">
                    {{ $label }} <span class="opacity-70">{{ number_format($hitung[$k]) }}</span>
                </a>
            @endforeach
            <input type="hidden" name="filter" value="{{ $filter }}">
            <input type="text" name="cari" value="{{ $cari }}" placeholder="Cari nama / SKU Shopee" class="border rounded px-3 py-1.5 text-sm ml-2">
            <button class="border rounded px-3 py-1.5 text-sm">Cari</button>
            <div class="ml-auto">@include('erp._partials.per-page-select')</div>
        </form>

        <div class="overflow-x-auto mt-3">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="text-left px-3 py-2">Produk Shopee</th>
                        <th class="text-left px-3 py-2">SKU Shopee</th>
                        <th class="text-right px-3 py-2">Harga</th>
                        <th class="text-right px-3 py-2">Stok</th>
                        <th class="text-left px-3 py-2">Produk ERP</th>
                        <th class="text-left px-3 py-2 w-72">Pasang SKU ERP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $it)
                        <tr class="border-t align-top">
                            <td class="px-3 py-2">
                                <div class="font-semibold text-gray-800">{{ $it->nama }}</div>
                                @if ($it->nama_model)<div class="text-xs text-gray-500">{{ $it->nama_model }}</div>@endif
                                <div class="text-[11px] text-gray-400">item {{ $it->item_id }}{{ $it->model_id ? ' · model ' . $it->model_id : '' }}{{ $it->status && $it->status !== 'NORMAL' ? ' · ' . $it->status : '' }}</div>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $it->sku() ?? '—' }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">{{ $it->harga !== null ? number_format($it->harga, 0, ',', '.') : '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ $it->stok ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @if ($it->product)
                                    <div class="font-mono text-xs">{{ $it->product->sku }}</div>
                                    <div class="text-xs text-gray-500">{{ $it->product->name }}</div>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded {{ $it->peta_sumber === 'manual' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">{{ $it->peta_sumber === 'manual' ? 'MANUAL' : 'SKU SAMA' }}</span>
                                @else
                                    <span class="text-xs font-semibold text-red-600">Belum dipetakan</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('settings.integrations.shopee.peta-sku.simpan', $it->id) }}" class="flex gap-1">@csrf @method('PUT')
                                    <input type="text" name="sku_erp" value="{{ $it->peta_sumber === 'manual' ? $it->product?->sku : '' }}" placeholder="SKU ERP"
                                           class="border rounded px-2 py-1 text-xs font-mono w-40">
                                    <button class="border border-blue-300 text-blue-700 hover:bg-blue-50 px-2 py-1 rounded text-xs font-semibold">Simpan</button>
                                </form>
                                @if ($it->peta_sumber === 'manual')<p class="text-[10px] text-gray-400 mt-1">Kosongkan lalu Simpan untuk kembali ke cocok SKU.</p>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">
                            {{ $hitung['semua'] ? 'Tidak ada baris untuk filter ini.' : 'Katalog belum ditarik — klik "Tarik katalog sekarang".' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $items->links() }}</div>
    </div>
</div>
@endsection
