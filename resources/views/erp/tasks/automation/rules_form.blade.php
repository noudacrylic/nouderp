@extends('layouts.erp')

@section('content')
@php
    $isEdit = $mode === 'edit';
    $isStock = $type === 'stock';
    $action = $isEdit
        ? route('tasks.automation.rules.update', ['automationType' => $type, 'rule' => $rule->id])
        : route('tasks.automation.rules.store', ['automationType' => $type]);

    $heading = ($isEdit ? 'Edit Rule ' : 'Tambah Rule ') . ($isStock ? 'Otomasi Stok' : 'Otomasi Pesanan');

    $placeholders = $isStock
        ? '{product}, {stock}, {min}'
        : '{product}, {so}, {qty}';
@endphp

@include('erp.tasks.automation._subtabs', ['current' => $isStock ? 'stok' : 'pesanan'])

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $heading }}</h1>
    <a href="{{ route('tasks.automation.rules.index', ['automationType' => $type]) }}" class="text-sm text-gray-500">← Kembali</a>
</div>

@if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-3 text-sm">
        <ul class="list-disc pl-5">@foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="bg-white rounded shadow p-4 space-y-4 max-w-3xl">
    @csrf
    @if($isEdit) @method('PATCH') @endif

    @php
        $selectedProductId = (int) old('product_id', $rule->product_id ?? 0);
        $selectedProduct = $selectedProductId ? $products->firstWhere('id', $selectedProductId) : null;
        $fmt = fn($v) => $v ? rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') : '—';
        $selectedLabel = $selectedProduct
            ? ($selectedProduct->name . ' (' . $selectedProduct->sku . ')')
            : '';
    @endphp
    <div>
        <label class="block text-xs text-gray-500 mb-1">Produk *</label>
        <div class="relative" id="productPicker">
            <input type="hidden" name="product_id" id="productPickerId" value="{{ $selectedProductId ?: '' }}" required>
            <input type="text" id="productPickerSearch" autocomplete="off"
                   value="{{ $selectedLabel }}"
                   placeholder="Ketik nama produk atau SKU…"
                   class="w-full border rounded px-2 py-2 text-sm pr-8">
            <button type="button" id="productPickerClear"
                    class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 text-sm {{ $selectedProductId ? '' : 'hidden' }}"
                    title="Hapus pilihan">×</button>
            <div id="productPickerDropdown" class="absolute left-0 right-0 mt-1 bg-white border border-gray-200 rounded shadow-lg max-h-72 overflow-y-auto z-50 hidden"></div>
        </div>
        @if($isStock)
            <p class="text-[11px] text-gray-400 mt-1">
                Threshold pakai kolom <em>Min Stock</em> di master produk. Atur dulu min stock di
                <a href="/erp/inventory/products" class="text-blue-600 hover:underline">Inventory → Produk</a> sebelum bikin rule.
            </p>
        @endif
    </div>

    @php
        $productsJs = $products->map(fn($p) => [
            'id'        => $p->id,
            'name'      => $p->name,
            'sku'       => $p->sku,
            // Stok fisik live (SUM qty_on_hand) — bukan kolom products.stock yang statis.
            'stock'     => $isStock ? $fmt($p->stok_fisik) : null,
            'min_stock' => $isStock ? $fmt($p->min_stock) : null,
        ])->values();
    @endphp
    @push('scripts')
    <script>
    (function () {
        const products = @json($productsJs);
        const isStock = @json($isStock);

        const wrap = document.getElementById('productPicker');
        const idInput = document.getElementById('productPickerId');
        const search = document.getElementById('productPickerSearch');
        const clearBtn = document.getElementById('productPickerClear');
        const dropdown = document.getElementById('productPickerDropdown');

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
        }

        function render(q) {
            const needle = q.trim().toLowerCase();
            const matches = !needle
                ? products.slice(0, 50)
                : products.filter(p =>
                    (p.name || '').toLowerCase().includes(needle) ||
                    (p.sku  || '').toLowerCase().includes(needle)
                  ).slice(0, 50);

            if (matches.length === 0) {
                dropdown.innerHTML = '<div class="px-3 py-2 text-xs text-gray-400 italic">Tidak ada produk cocok.</div>';
            } else {
                dropdown.innerHTML = matches.map(p => {
                    const meta = isStock
                        ? ` — stok: ${escapeHtml(p.stock)} / min: ${escapeHtml(p.min_stock)}`
                        : '';
                    return `<button type="button" class="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 border-b border-gray-100 last:border-b-0" data-id="${p.id}" data-label="${escapeHtml(p.name)} (${escapeHtml(p.sku)})">
                        <span class="font-medium text-gray-800">${escapeHtml(p.name)}</span>
                        <span class="text-gray-500">(${escapeHtml(p.sku)})</span>${meta ? `<span class="text-[11px] text-gray-400">${meta}</span>` : ''}
                    </button>`;
                }).join('');
            }
            dropdown.classList.remove('hidden');
        }

        function pickProduct(id, label) {
            idInput.value = id;
            search.value = label;
            clearBtn.classList.remove('hidden');
            dropdown.classList.add('hidden');
        }
        function clearProduct() {
            idInput.value = '';
            search.value = '';
            clearBtn.classList.add('hidden');
            dropdown.classList.add('hidden');
            search.focus();
        }

        search.addEventListener('focus', () => render(search.value));
        search.addEventListener('input', () => {
            // Saat user ketik, anggap pilihan lama batal sampai dia klik hasil
            idInput.value = '';
            clearBtn.classList.remove('hidden');
            render(search.value);
        });
        dropdown.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-id]');
            if (!btn) return;
            e.preventDefault();
            pickProduct(btn.dataset.id, btn.dataset.label);
        });
        clearBtn.addEventListener('click', clearProduct);
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) dropdown.classList.add('hidden');
        });

        // Guard submit: pastikan produk terpilih (hidden input boleh required tapi browser
        // tidak validasi hidden field — perlu cek manual).
        const form = idInput.closest('form');
        form?.addEventListener('submit', (e) => {
            if (!idInput.value) {
                e.preventDefault();
                search.focus();
                search.classList.add('border-red-400');
                render(search.value);
                setTimeout(() => search.classList.remove('border-red-400'), 1500);
            }
        });
    })();
    </script>
    @endpush

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Ditugaskan ke *</label>
            <select name="assignee_user_id" required class="w-full border rounded px-2 py-2 text-sm">
                <option value="">— Pilih user —</option>
                @foreach($assignableUsers as $u)
                    <option value="{{ $u->id }}" @selected(old('assignee_user_id', $rule->assignee_user_id) == $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Kategori</label>
            <select name="category_id" class="w-full border rounded px-2 py-2 text-sm">
                <option value="">— Tanpa Kategori —</option>
                @foreach($categories as $c)
                    <option value="{{ $c->id }}" @selected(old('category_id', $rule->category_id) == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
            <select name="priority" class="w-full border rounded px-2 py-2 text-sm">
                @foreach(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $v => $lbl)
                    <option value="{{ $v }}" @selected(old('priority', $rule->priority ?? 'normal') === $v)>{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="border-t pt-3">
        <div class="text-xs font-semibold text-gray-600 mb-2">Template Task (opsional)</div>
        <div class="space-y-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Judul Task</label>
                <input type="text" name="title_template" value="{{ old('title_template', $rule->title_template) }}"
                       placeholder="Kosongkan untuk default — placeholder: {{ $placeholders }}"
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Deskripsi Task</label>
                <textarea name="description_template" rows="3"
                          placeholder="Kosongkan untuk default — placeholder: {{ $placeholders }}"
                          class="w-full border rounded px-3 py-2 text-sm">{{ old('description_template', $rule->description_template) }}</textarea>
            </div>
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true))>
        Rule aktif
    </label>

    <div class="flex gap-2 pt-2 border-t">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        <a href="{{ route('tasks.automation.rules.index', ['automationType' => $type]) }}" class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
    </div>
</form>
@endsection
