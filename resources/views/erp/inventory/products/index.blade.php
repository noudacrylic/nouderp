@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Produk</h1>
    <div class="flex gap-2">
        <a href="{{ route('inventory.products.export', request()->query()) }}" class="bg-slate-600 hover:bg-slate-700 text-white px-3 py-2 rounded text-sm" title="Download produk sesuai filter aktif">📥 Download Excel</a>
        <a href="{{ route('inventory.products.bulk-import') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">📤 Upload Excel</a>
        <a href="{{ route('inventory.products.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">+ Tambah Produk</a>
    </div>
</div>

<form method="GET" data-live-results="#list-results" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['name' => 'search', 'placeholder' => 'Cari SKU / nama produk...'])

    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="ready" @selected(request('type') == 'ready')>Ready Stock</option>
            <option value="preorder" @selected(request('type') == 'preorder')>Preorder</option>
            <option value="service" @selected(request('type') == 'service')>Service</option>
            <option value="non_stock" @selected(request('type') == 'non_stock')>Non Stock</option>
            <option value="bundle" @selected(request('type') == 'bundle')>Bundle</option>
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="active" @selected((request('status') ?? 'active') == 'active')>Aktif</option>
            <option value="archived" @selected(request('status') == 'archived')>Arsip</option>
            <option value="all" @selected(request('status') == 'all')>Semua</option>
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Dijual</label>
        <select name="sellable" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="yes" @selected(request('sellable') == 'yes')>Dijual</option>
            <option value="no" @selected(request('sellable') == 'no')>Tidak Dijual</option>
        </select>
    </div>
</form>

{{-- Toggle "Dijual": CSS eksplisit (tidak bergantung Tailwind JIT/peer-checked yang tak tergenerate via CDN). --}}
<style>
    .sellable-toggle { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .sellable-switch {
        position: relative; display: inline-block; flex: 0 0 auto;
        width: 36px; height: 20px; border-radius: 9999px;
        background: #d1d5db; transition: background .15s ease;
    }
    .sellable-switch::after {
        content: ''; position: absolute; top: 2px; left: 2px;
        width: 16px; height: 16px; border-radius: 9999px;
        background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.25);
        transition: transform .15s ease;
    }
    .sellable-toggle:checked + .sellable-switch { background: #22c55e; }
    .sellable-toggle:checked + .sellable-switch::after { transform: translateX(16px); }
    .sellable-toggle:focus-visible + .sellable-switch { box-shadow: 0 0 0 3px rgba(34,197,94,.35); }

    /* Toggle "Jubelio" (sync_to_jubelio) — sama struktur, warna indigo agar beda dari Dijual. */
    .jubelio-toggle { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .jubelio-switch {
        position: relative; display: inline-block; flex: 0 0 auto;
        width: 36px; height: 20px; border-radius: 9999px;
        background: #d1d5db; transition: background .15s ease;
    }
    .jubelio-switch::after {
        content: ''; position: absolute; top: 2px; left: 2px;
        width: 16px; height: 16px; border-radius: 9999px;
        background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.25);
        transition: transform .15s ease;
    }
    .jubelio-toggle:checked + .jubelio-switch { background: #6366f1; }
    .jubelio-toggle:checked + .jubelio-switch::after { transform: translateX(16px); }
    .jubelio-toggle:focus-visible + .jubelio-switch { box-shadow: 0 0 0 3px rgba(99,102,241,.35); }
</style>

<div id="list-results">
<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Produk</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-center">Dijual</th>
                <th class="px-3 py-2 text-center">Jubelio</th>
                <th class="px-3 py-2 text-left">Unit</th>
                <th class="px-3 py-2 text-right">Harga Dasar</th>
                <th class="px-3 py-2 text-center w-40">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $product)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('inventory.products.setup', $product->id) }}">
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-xs font-mono text-gray-500 whitespace-nowrap">
                                {{ $product->sku }}
                                @include('erp.purchasing._partials.copy-btn', ['value' => $product->sku])
                            </span>
                            <span class="font-medium text-gray-900">{{ $product->name }}</span>
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        @php
                            $typeBadge = match($product->sale_type) {
                                'ready'     => 'bg-blue-100 text-blue-700',
                                'bundle'    => 'bg-purple-100 text-purple-700',
                                'preorder'  => 'bg-amber-100 text-amber-700',
                                'service'   => 'bg-emerald-100 text-emerald-700',
                                'non_stock' => 'bg-gray-100 text-gray-600',
                                default     => 'bg-blue-50 text-blue-700',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $typeBadge }}">
                            {{ str_replace('_', ' ', $product->sale_type) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        @if ($product->is_active)
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-green-100 text-green-700">Aktif</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-500">Arsip</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none" title="Klik untuk ubah: muncul di Kasir/POS/Penawaran/SO/Faktur/Promosi atau tidak">
                            <input type="checkbox" class="sellable-toggle" data-product-id="{{ $product->id }}" @checked($product->is_sellable)>
                            <span class="sellable-switch"></span>
                            <span class="sellable-label text-xs font-semibold {{ $product->is_sellable ? 'text-green-600' : 'text-gray-400' }}">{{ $product->is_sellable ? 'Dijual' : 'Tidak' }}</span>
                        </label>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none" title="Klik untuk ubah: sinkron stok &amp; harga produk ini ke Jubelio">
                            <input type="checkbox" class="jubelio-toggle" data-product-id="{{ $product->id }}" @checked($product->sync_to_jubelio)>
                            <span class="jubelio-switch"></span>
                            <span class="jubelio-label text-xs font-semibold {{ $product->sync_to_jubelio ? 'text-indigo-600' : 'text-gray-400' }}">{{ $product->sync_to_jubelio ? 'Sinkron' : 'Tidak' }}</span>
                        </label>
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ $product->base_unit ?? '-' }}</td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex items-center justify-end gap-1">
                            <span class="text-gray-500 text-xs">Rp</span>
                            <input type="text" value="{{ number_format($product->display_price, 0, ',', '.') }}"
                                   data-product-id="{{ $product->id }}"
                                   class="price-inline rupiah-input w-24 border rounded px-2 py-1 text-right font-medium text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button type="button"
                                    class="price-save w-6 h-6 flex items-center justify-center rounded transition bg-gray-100 text-gray-500 hover:bg-blue-100 hover:text-blue-700"
                                    title="Simpan harga (atau tekan Enter)">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 4h11l3 3v13a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 4v5h7V4M8 20v-6h8v6"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            @if (!$product->is_active)
                                <form method="POST" action="{{ route('inventory.products.restore', $product->id) }}">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Restore</button>
                                </form>
                            @elseif ($product->is_used)
                                <form method="POST" action="{{ route('inventory.products.archive', $product->id) }}"
                                      onsubmit="return confirm('Archive produk ini?')">
                                    @csrf
                                    <button class="bg-yellow-500 text-white px-2 py-1 rounded text-xs">Archive</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('inventory.products.destroy', $product->id) }}"
                                      onsubmit="return confirm('Hapus produk ini secara permanen?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada produk.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($products instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $products->links() }}</div>
@endif
</div>{{-- /#list-results --}}

@include('erp.purchasing._partials.list-scripts')

@push('scripts')
<script>
    // Handler di-delegasikan ke document agar tetap jalan untuk baris yang diganti
    // via live-search AJAX (#list-results di-swap tanpa reload).
    // Catatan: format ribuan ".rupiah-input" sudah ditangani formatter global di layout.

    // ── Edit harga inline: simpan via tombol atau Enter (seperti min-stok di Stok) ──
    const CHECK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';

    function savePrice(input) {
        const wrap  = input.closest('div');
        const btn   = wrap ? wrap.querySelector('.price-save') : null;
        const price = window.cleanNumber ? window.cleanNumber(input.value) : input.value.replace(/\./g, '');
        const origBtn = btn ? btn.innerHTML : '';

        if (btn) btn.disabled = true;
        fetch('/erp/inventory/products/update-price', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ product_id: input.dataset.productId, price: price })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    input.classList.add('border-green-400', 'bg-green-50');
                    if (btn) {
                        btn.classList.remove('bg-gray-100', 'text-gray-500', 'hover:bg-blue-100', 'hover:text-blue-700');
                        btn.classList.add('bg-green-100', 'text-green-700');
                        btn.innerHTML = CHECK_SVG;
                        btn.title = 'Tersimpan';
                    }
                    setTimeout(() => {
                        input.classList.remove('border-green-400', 'bg-green-50');
                        if (btn) {
                            btn.classList.add('bg-gray-100', 'text-gray-500', 'hover:bg-blue-100', 'hover:text-blue-700');
                            btn.classList.remove('bg-green-100', 'text-green-700');
                            btn.innerHTML = origBtn;
                            btn.title = 'Simpan harga (atau tekan Enter)';
                        }
                    }, 1500);
                } else if (btn) {
                    btn.innerHTML = origBtn;
                }
            })
            .catch(() => { if (btn) btn.innerHTML = origBtn; })
            .finally(() => { if (btn) btn.disabled = false; });
    }

    // Klik tombol simpan.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.price-save');
        if (!btn) return;
        const input = btn.closest('div')?.querySelector('.price-inline');
        if (input) savePrice(input);
    });

    // Enter di kotak harga → simpan (tanpa reload).
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.price-inline');
        if (!input) return;
        e.preventDefault();
        savePrice(input);
    });

    // Toggle "Dijual" (is_sellable) langsung dari index — tanpa buka edit.
    document.addEventListener('change', function (e) {
        const el = e.target.closest('.sellable-toggle');
        if (!el) return;
        const on = el.checked;
        const label = el.closest('label')?.querySelector('.sellable-label');
        el.disabled = true;
        fetch('/erp/inventory/products/update-sellable', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ product_id: el.dataset.productId, is_sellable: on ? 1 : 0 })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (label) {
                        label.textContent = data.is_sellable ? 'Dijual' : 'Tidak';
                        label.classList.toggle('text-green-600', data.is_sellable);
                        label.classList.toggle('text-gray-400', !data.is_sellable);
                    }
                } else {
                    el.checked = !on; // gagal → kembalikan
                }
            })
            .catch(() => { el.checked = !on; })
            .finally(() => { el.disabled = false; });
    });

    // Toggle "Jubelio" (sync_to_jubelio) langsung dari index — tanpa buka edit.
    document.addEventListener('change', function (e) {
        const el = e.target.closest('.jubelio-toggle');
        if (!el) return;
        const on = el.checked;
        const label = el.closest('label')?.querySelector('.jubelio-label');
        el.disabled = true;
        fetch('/erp/inventory/products/update-jubelio', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ product_id: el.dataset.productId, sync_to_jubelio: on ? 1 : 0 })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (label) {
                        label.textContent = data.sync_to_jubelio ? 'Sinkron' : 'Tidak';
                        label.classList.toggle('text-indigo-600', data.sync_to_jubelio);
                        label.classList.toggle('text-gray-400', !data.sync_to_jubelio);
                    }
                } else {
                    el.checked = !on; // gagal → kembalikan
                }
            })
            .catch(() => { el.checked = !on; })
            .finally(() => { el.disabled = false; });
    });
</script>
@endpush
@endsection
