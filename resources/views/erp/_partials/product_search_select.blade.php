{{--
    Live-search single-select produk (Alpine inline, no AJAX — pakai $products yang sudah di-load).
    Params:
      $products   : Collection produk (butuh id, sku, name)
      $name       : nama hidden input (mis. 'product_id')
      $selected   : id terpilih (opsional)
      $placeholder: teks placeholder (opsional)
      $includeAll : true → tampilkan opsi "— Semua Produk —" (kosong = semua). Default false.
--}}
@php
    $psItems = $products->map(fn($p) => [
        'id'     => $p->id,
        'label'  => $p->sku . ' – ' . $p->name,
        'search' => strtolower(trim($p->sku . ' ' . $p->name)),
    ])->values();

    $psSelectedLabel = '';
    if (!empty($selected)) {
        $sel = $products->firstWhere('id', $selected);
        $psSelectedLabel = $sel ? ($sel->sku . ' – ' . $sel->name) : '';
    }
    $psPlaceholder = $placeholder ?? '— Pilih Produk —';
    $psIncludeAll  = $includeAll ?? false;
@endphp
<div class="relative"
     x-data="{
        open: false,
        query: @js($psSelectedLabel),
        selectedId: @js((string)($selected ?? '')),
        items: {{ \Illuminate\Support\Js::from($psItems) }},
        get filtered() {
            const q = this.query.toLowerCase().trim();
            const list = q ? this.items.filter(i => i.search.includes(q)) : this.items;
            return list.slice(0, 50);
        },
        pick(item) { this.selectedId = String(item.id); this.query = item.label; this.open = false; },
        clearSel() { this.selectedId = ''; this.query = ''; this.open = false; },
     }"
     @click.outside="open = false">

    <input type="hidden" name="{{ $name }}" :value="selectedId">

    <div class="relative">
        <input type="text" x-model="query" autocomplete="off"
               @focus="open = true" @click="open = true"
               @input="selectedId = ''; open = true"
               placeholder="{{ $psPlaceholder }}"
               class="w-full border border-gray-200 rounded-xl pl-3 pr-8 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
        <button type="button" x-show="query.length > 0" @click="clearSel()"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-sm" title="Hapus">&times;</button>
    </div>

    <div x-show="open" x-transition.opacity style="display:none"
         class="absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-lg max-h-64 overflow-auto py-1">
        @if($psIncludeAll)
            <button type="button" @click="clearSel()"
                    class="w-full text-left px-3 py-2 text-sm text-gray-500 hover:bg-blue-50">— Semua Produk —</button>
        @endif
        <template x-for="item in filtered" :key="item.id">
            <button type="button" @click="pick(item)"
                    class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-blue-50"
                    :class="{ 'bg-blue-50 font-semibold': selectedId === String(item.id) }"
                    x-text="item.label"></button>
        </template>
        <div x-show="filtered.length === 0" class="px-3 py-3 text-sm text-gray-400 text-center">Produk tidak ditemukan</div>
    </div>
</div>
