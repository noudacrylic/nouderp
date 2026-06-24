@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Stok</h1>
</div>

<form method="GET" data-live-results="#list-results" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['name' => 'search', 'placeholder' => 'Cari SKU / nama produk...'])

    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="ready" @selected(request('type') == 'ready')>Ready Stock</option>
            <option value="preorder" @selected(request('type') == 'preorder')>Preorder</option>
            <option value="bundle" @selected(request('type') == 'bundle')>Bundle</option>
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Status Stok</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="ada" @selected(request('status') == 'ada')>Ada</option>
            <option value="menipis" @selected(request('status') == 'menipis')>Menipis</option>
            <option value="habis" @selected(request('status') == 'habis')>Habis</option>
            <option value="minus" @selected(request('status') == 'minus')>Minus</option>
        </select>
    </div>
</form>

<div id="list-results">
<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">SKU</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-center">Tipe</th>
                <th class="px-3 py-2 text-right">Stok Fisik</th>
                <th class="px-3 py-2 text-right">Cadangan</th>
                <th class="px-3 py-2 text-right">Dipesan</th>
                <th class="px-3 py-2 text-right">Dikirim</th>
                <th class="px-3 py-2 text-right">Tersedia</th>
                <th class="px-3 py-2 text-center">Min Stok / Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($stocks as $stock)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('inventory.products.ledger', $stock->id) }}">
                    <td class="px-3 py-2 font-mono whitespace-nowrap">
                        {{ $stock->sku }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $stock->sku])
                    </td>
                    <td class="px-3 py-2">{{ $stock->name }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase
                            {{ $stock->type == 'ready' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' }}">
                            {{ ucfirst($stock->type) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right">{{ format_qty($stock->qty_on_hand) }}</td>
                    <td class="px-3 py-2 text-right">{{ format_qty($stock->reserved) }}</td>
                    <td class="px-3 py-2 text-right">{{ format_qty($stock->ordered) }}</td>
                    <td class="px-3 py-2 text-right text-blue-600">{{ format_qty($stock->shipped ?? 0) }}</td>
                    <td class="px-3 py-2 text-right text-green-600 font-semibold">{{ format_qty($stock->available) }}</td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex items-center justify-center gap-1"
                             x-data="minStockEditor({{ $stock->id }}, {{ $stock->min_stock ?? 'null' }}, {{ $stock->available }}, '{{ $stock->type }}')">
                            <input type="number" x-model="value" min="0" step="1"
                                   placeholder="—"
                                   @keydown.enter.prevent="save()"
                                   class="w-16 text-right border border-gray-200 rounded px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-blue-300">
                            <button @click="save()" type="button"
                                    title="Simpan (atau tekan Enter)"
                                    class="w-6 h-6 flex items-center justify-center rounded transition"
                                    :class="saved ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500 hover:bg-blue-100 hover:text-blue-700'">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 4h11l3 3v13a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 4v5h7V4M8 20v-6h8v6"/>
                                </svg>
                            </button>
                            <span class="text-[10px] px-2 py-0.5 rounded font-bold border ml-1"
                                  :class="statusClass">
                                <span x-text="statusLabel"></span>
                            </span>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Tidak ada produk ditemukan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($stocks instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $stocks->links() }}</div>
@endif
</div>{{-- /#list-results --}}

@include('erp.purchasing._partials.list-scripts')

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function minStockEditor(productId, initialMin, availableQty, saleType) {
    // Status badge berbasis stok TERSEDIA (sellable - reservasi + preorder), bukan stok fisik —
    // selaras dengan stock_status di StockController. availableQty = $stock->available.
    const computeStatus = (minVal, qty, type) => {
        if (type === 'preorder') return { label: 'P.O', cls: 'bg-purple-50 text-purple-600 border-purple-200' };
        if (qty < 0)  return { label: 'Minus',     cls: 'bg-red-50 text-red-600 border-red-200' };
        if (qty == 0) return { label: 'Habis',     cls: 'bg-red-50 text-red-600 border-red-200' };
        if (minVal !== null && qty <= minVal) return { label: 'Menipis', cls: 'bg-amber-50 text-amber-600 border-amber-200' };
        return { label: 'Ada', cls: 'bg-green-50 text-green-600 border-green-200' };
    };

    const initial = computeStatus(initialMin, availableQty, saleType);

    return {
        productId,
        value: initialMin !== null ? initialMin : '',
        saved: false,
        statusLabel: initial.label,
        statusClass: initial.cls,
        get computedMin() { return this.value === '' ? null : parseFloat(this.value); },
        async save() {
            try {
                const res = await fetch(`/erp/inventory/products/${this.productId}/min-stock`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ min_stock: this.computedMin }),
                });
                if (res.ok) {
                    this.saved = true;
                    const s = computeStatus(this.computedMin, availableQty, saleType);
                    this.statusLabel = s.label;
                    this.statusClass = s.cls;
                    setTimeout(() => this.saved = false, 2000);
                }
            } catch (e) {}
        }
    };
}
</script>
@endpush
@endsection
