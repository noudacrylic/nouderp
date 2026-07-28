@csrf
@php
    // Data awal untuk komponen variasi.
    $isVariant = !empty($product->variant_axes);
    $single = null; $savedCombos = []; $defaultKey = null;
    foreach ($variants as $v) {
        $entry = [
            'product_id' => $v->product_id,
            'sku'        => $v->product->sku ?? '(SKU dihapus)',
            'name'       => $v->product->name ?? '',
        ];
        if ($isVariant) {
            $opts = $v->option_values ?? [];
            $key = implode(' / ', $opts);
            $savedCombos[] = array_merge($entry, [
                'opts'           => $opts,
                'key'            => $key,
                'image_media_id' => $v->image_media_id,
            ]);
            if ($v->is_default) $defaultKey = $key;
        } else {
            $single = $entry;
        }
    }
    // Daftar foto galeri (untuk pemilih gambar per-varian).
    $galleryImages = $product->exists
        ? $product->media->where('group', 'gallery')->where('kind', 'image')->sortBy('sort_order')->values()
            ->map(fn($m, $i) => [
                'id'    => $m->id,
                'url'   => $m->url,
                'label' => ($m->is_primary ? '★ ' : '') . 'Foto ' . ($i + 1),
            ])->all()
        : [];
    $vmInit = [
        'mode'        => $isVariant ? 'variant' : 'single',
        'single'      => $single,
        'axes'        => $product->variant_axes ?: [],
        'savedCombos' => $savedCombos,
        'defaultKey'  => $defaultKey,
        'images'      => $galleryImages,
    ];
@endphp

<div class="max-w-4xl mx-auto" x-data="storeVariants({{ Js::from($vmInit) }})">

    {{-- Informasi utama --}}
    <div class="bg-white rounded shadow p-4 space-y-4">
        <h2 class="text-sm font-semibold text-gray-700 border-b pb-2">Informasi Produk</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Nama Tampil Web <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $product->name) }}" required
                       class="border rounded px-2 py-1.5 w-full" placeholder="mis. Akrilik Bening 3mm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Kategori</label>
                <select name="store_category_id" class="border rounded px-2 py-1.5 w-full">
                    <option value="">— Tanpa kategori —</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected((int) old('store_category_id', $product->store_category_id ?? 0) === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">Slug (URL)</label>
            <input type="text" name="slug" value="{{ old('slug', $product->slug) }}"
                   class="border rounded px-2 py-1.5 w-full md:w-1/2" placeholder="otomatis dari nama bila kosong">
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">Deskripsi Singkat</label>
            <input type="text" name="short_description" value="{{ old('short_description', $product->short_description) }}"
                   maxlength="255" class="border rounded px-2 py-1.5 w-full" placeholder="Ringkasan 1 baris untuk kartu/preview">
        </div>

        <div>
            <label class="block text-xs text-gray-500 mb-1">Deskripsi Lengkap</label>
            <textarea name="description" rows="6" class="border rounded px-2 py-1.5 w-full"
                      placeholder="Penjelasan detail produk (untuk SEO & halaman produk)">{{ old('description', $product->description) }}</textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-1">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured ?? false))>
                Tampilkan sebagai unggulan
            </label>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Urutan</label>
                <input type="number" name="sort_order" value="{{ old('sort_order', $product->sort_order ?? 0) }}"
                       class="border rounded px-2 py-1.5 w-28">
            </div>
        </div>
    </div>

    {{-- ===================== VARIASI / SKU ===================== --}}
    <div class="bg-white rounded shadow p-4 space-y-4 mt-4">
        <h2 class="text-sm font-semibold text-gray-700 border-b pb-2">Varian / SKU</h2>

        <input type="hidden" name="variant_mode" :value="mode">

        {{-- Pilih mode --}}
        <div class="flex gap-2">
            <button type="button" @click="setMode('single')"
                    :class="mode==='single' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'"
                    class="px-3 py-1.5 rounded text-sm">Tanpa Variasi</button>
            <button type="button" @click="setMode('variant')"
                    :class="mode==='variant' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600'"
                    class="px-3 py-1.5 rounded text-sm">Pakai Variasi</button>
        </div>

        {{-- ---------- MODE: TANPA VARIASI ---------- --}}
        <div x-show="mode==='single'" x-cloak class="space-y-2">
            <p class="text-xs text-gray-400">Pilih 1 SKU (wajib bertanda "Dijual"). Harga &amp; stok diambil otomatis dari SKU.</p>
            <div x-show="single.product_id" class="flex items-center gap-2 text-sm bg-gray-50 border rounded px-3 py-2">
                <span class="font-medium" x-text="single.sku"></span>
                <span class="text-gray-400" x-text="'— ' + single.name"></span>
                <button type="button" @click="clearSingle()" class="ml-auto text-red-600 hover:text-red-800 text-xs">Ganti</button>
            </div>
            <div x-show="!single.product_id" class="relative">
                <input type="text" x-model="singleSearch" @input.debounce.300ms="searchSingle()" @focus="searchSingle()"
                       placeholder="Cari SKU / nama produk..." class="border rounded px-2 py-1.5 w-full">
                <div x-show="singleResults.length" x-cloak class="absolute z-20 bg-white border rounded shadow mt-1 w-full max-h-64 overflow-y-auto text-sm">
                    <template x-for="r in singleResults" :key="r.id">
                        <div @click="pickSingle(r)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer flex justify-between gap-2">
                            <span><span class="font-medium" x-text="r.sku"></span> — <span x-text="r.name"></span></span>
                            <span class="text-gray-400" x-text="formatRp(r.price)"></span>
                        </div>
                    </template>
                </div>
            </div>
            {{-- hidden submit --}}
            <template x-if="mode==='single'">
                <input type="hidden" name="single_product_id" :value="single.product_id || ''">
            </template>
        </div>

        {{-- ---------- MODE: PAKAI VARIASI ---------- --}}
        <div x-show="mode==='variant'" x-cloak class="space-y-4">
            <p class="text-xs text-gray-400">Buat sumbu variasi (mis. <b>Orientasi</b>: Potrait, Landscape; <b>Packing</b>: Kayu, Tanpa). Tiap kombinasi dipetakan ke 1 SKU. Maks 2 variasi.</p>

            {{-- Definisi sumbu --}}
            <template x-for="(ax, ai) in axes" :key="ai">
                <div class="border rounded p-3 bg-gray-50 space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 w-16" x-text="'Variasi ' + (ai+1)"></span>
                        <input type="text" x-model="ax.name" @input="rebuild()" placeholder="Nama variasi (mis. Orientasi)"
                               class="border rounded px-2 py-1 text-sm flex-1">
                        <button type="button" @click="removeAxis(ai)" class="text-red-600 hover:text-red-800 text-xs">Hapus</button>
                    </div>
                    <div class="flex flex-wrap gap-2 pl-16">
                        <template x-for="(opt, oi) in ax.options" :key="oi">
                            <div class="flex items-center gap-1">
                                <input type="text" :value="opt" @input="ax.options[oi]=$event.target.value; rebuild()"
                                       placeholder="opsi" class="border rounded px-2 py-1 text-sm w-32">
                                <button type="button" @click="removeOption(ax, oi)" class="text-gray-400 hover:text-red-600 text-xs">✕</button>
                            </div>
                        </template>
                        <button type="button" @click="addOption(ax)" class="text-blue-600 text-xs hover:underline">+ Opsi</button>
                    </div>
                </div>
            </template>

            <button type="button" x-show="axes.length < 2" @click="addAxis()"
                    class="text-blue-600 text-sm hover:underline">+ Tambah Variasi</button>

            {{-- Matriks kombinasi → SKU --}}
            <div x-show="combos.length" x-cloak class="border rounded overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b text-gray-500">
                        <tr>
                            <template x-for="(ax, ai) in axes" :key="ai">
                                <th class="px-3 py-2 text-left" x-text="ax.name || ('Variasi ' + (ai+1))"></th>
                            </template>
                            <th class="px-3 py-2 text-left">SKU</th>
                            <th class="px-3 py-2 text-left w-56">Gambar <span class="font-normal text-gray-400">(opsional)</span></th>
                            <th class="px-3 py-2 text-center w-20">Default</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in combos" :key="c.key">
                            <tr class="border-b">
                                <template x-for="(o, oi) in c.opts" :key="oi">
                                    <td class="px-3 py-2 font-medium" x-text="o"></td>
                                </template>
                                <td class="px-3 py-2">
                                    <div x-show="c.product_id" class="flex items-center gap-2">
                                        <span class="font-medium" x-text="c.sku"></span>
                                        <span class="text-gray-400 text-xs" x-text="'— ' + c.name"></span>
                                        <button type="button" @click="clearRow(c)" class="text-red-600 hover:text-red-800 text-xs">Ganti</button>
                                    </div>
                                    <div x-show="!c.product_id" class="relative">
                                        <input type="text" x-model="c.search" @input.debounce.300ms="searchRow(c)" @focus="searchRow(c)"
                                               placeholder="Cari SKU..." class="border rounded px-2 py-1 w-full">
                                        <div x-show="c.results.length" x-cloak class="absolute z-20 bg-white border rounded shadow mt-1 w-full max-h-56 overflow-y-auto text-sm">
                                            <template x-for="r in c.results" :key="r.id">
                                                <div @click="pickRow(c, r)" class="px-3 py-2 hover:bg-blue-50 cursor-pointer flex justify-between gap-2">
                                                    <span><span class="font-medium" x-text="r.sku"></span> — <span x-text="r.name"></span></span>
                                                    <span class="text-gray-400" x-text="formatRp(r.price)"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <template x-if="images.length">
                                        <div class="flex items-center gap-2">
                                            <select x-model="c.image_media_id" class="border rounded px-2 py-1 text-sm w-full">
                                                <option value="">— pakai galeri —</option>
                                                <template x-for="img in images" :key="img.id">
                                                    <option :value="img.id" x-text="img.label"></option>
                                                </template>
                                            </select>
                                            <template x-if="c.image_media_id">
                                                <img :src="imgUrl(c.image_media_id)" class="w-8 h-8 object-cover rounded border shrink-0" alt="">
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!images.length">
                                        <span class="text-gray-400 text-xs">Unggah foto di Galeri dulu</span>
                                    </template>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input type="radio" :checked="c.key===defaultKey" @change="defaultKey=c.key" :disabled="!c.product_id">
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- hidden submit: axes + filled combos --}}
            <template x-if="mode==='variant'">
                <div>
                    <template x-for="(ax, ai) in axes" :key="ai">
                        <div>
                            <input type="hidden" :name="`axes[${ai}][name]`" :value="ax.name">
                            <template x-for="(opt, oi) in ax.options.filter(o => o.trim() !== '')" :key="oi">
                                <input type="hidden" :name="`axes[${ai}][options][]`" :value="opt">
                            </template>
                        </div>
                    </template>
                    <template x-for="(c, fi) in filledCombos()" :key="c.key">
                        <div>
                            <input type="hidden" :name="`variants[${fi}][product_id]`" :value="c.product_id">
                            <input type="hidden" :name="`variants[${fi}][image_media_id]`" :value="c.image_media_id || ''">
                            <template x-for="(o, oi) in c.opts" :key="oi">
                                <input type="hidden" :name="`variants[${fi}][options][]`" :value="o">
                            </template>
                        </div>
                    </template>
                    <input type="hidden" name="default_index" :value="filledDefaultIndex()">
                </div>
            </template>
        </div>
    </div>

    {{-- Galeri media (foto/video) + galeri instansi (bukti sosial) --}}
    @if($product->exists)
        @include('erp.store.products._media')
        @include('erp.store.products._showcase')
    @endif

    {{-- SEO --}}
    <div class="bg-white rounded shadow p-4 space-y-4 mt-4">
        <h2 class="text-sm font-semibold text-gray-700 border-b pb-2">SEO (opsional)</h2>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Meta Title</label>
            <input type="text" name="meta_title" value="{{ old('meta_title', $product->meta_title) }}"
                   maxlength="255" class="border rounded px-2 py-1.5 w-full" placeholder="Kosong → pakai nama produk">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Meta Description</label>
            <input type="text" name="meta_description" value="{{ old('meta_description', $product->meta_description) }}"
                   maxlength="255" class="border rounded px-2 py-1.5 w-full">
        </div>
    </div>

    {{-- Aksi simpan — rata kanan, Terbitkan paling kanan (kebiasaan Noud) --}}
    <div class="flex items-center gap-2 mt-4 mb-8">
        <span class="mr-auto text-xs">
            Status saat ini:
            @if(($product->status ?? 'draft') === 'published')
                <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded">Published</span>
            @else
                <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded">Draft</span>
            @endif
        </span>
        <a href="{{ route('store.products.index') }}" class="text-gray-500 px-3 py-2 text-sm hover:underline">Batal</a>
        <button type="submit" name="action" value="draft"
                class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2 rounded text-sm font-semibold">Simpan sebagai Draft</button>
        <button type="submit" name="action" value="publish"
                class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Terbitkan</button>
    </div>
</div>

@push('scripts')
<script>
function storeVariants(init) {
    const api = '/erp/api/products/search?sellable_only=1&q=';
    return {
        mode: init.mode || 'single',
        single: init.single || { product_id: null, sku: '', name: '' },
        singleSearch: '', singleResults: [],
        axes: (init.axes && init.axes.length) ? init.axes.map(a => ({ name: a.name, options: [...a.options] })) : [],
        combos: [],
        defaultKey: init.defaultKey || null,
        _saved: init.savedCombos || [],
        images: init.images || [],

        init() {
            if (this.mode === 'variant') { this.ensureAxis(); this.rebuild(); }
            if (!this.single) this.single = { product_id: null, sku: '', name: '' };
        },
        setMode(m) {
            this.mode = m;
            if (m === 'variant') { this.ensureAxis(); this.rebuild(); }
        },
        ensureAxis() { if (this.axes.length === 0) this.addAxis(); },
        addAxis() { if (this.axes.length < 2) this.axes.push({ name: '', options: [''] }); },
        removeAxis(i) { this.axes.splice(i, 1); this.rebuild(); },
        addOption(ax) { ax.options.push(''); },
        removeOption(ax, oi) { ax.options.splice(oi, 1); this.rebuild(); },

        rebuild() {
            const lists = this.axes.map(a => a.options.map(o => o.trim()).filter(o => o !== '')).filter(l => l.length);
            if (lists.length === 0) { this.combos = []; return; }
            let res = [[]];
            for (const list of lists) {
                const nx = [];
                for (const c of res) for (const o of list) nx.push([...c, o]);
                res = nx;
            }
            const prev = {};
            this.combos.forEach(c => prev[c.key] = c);
            this._saved.forEach(c => { if (!(c.key in prev)) prev[c.key] = c; });
            this.combos = res.map(opts => {
                const key = opts.join(' / ');
                const ex = prev[key];
                return ex
                    ? { key, opts, product_id: ex.product_id || null, sku: ex.sku || '', name: ex.name || '', image_media_id: ex.image_media_id ? String(ex.image_media_id) : '', search: '', results: [] }
                    : { key, opts, product_id: null, sku: '', name: '', image_media_id: '', search: '', results: [] };
            });
            if (!this.combos.some(c => c.key === this.defaultKey)) {
                this.defaultKey = this.combos.length ? this.combos[0].key : null;
            }
        },

        used(except) {
            const ids = this.combos.filter(c => c !== except && c.product_id).map(c => c.product_id);
            return ids;
        },
        searchSingle() { fetch(api + encodeURIComponent(this.singleSearch.trim())).then(r => r.json()).then(d => this.singleResults = d); },
        pickSingle(r) { this.single = { product_id: r.id, sku: r.sku, name: r.name }; this.singleSearch = ''; this.singleResults = []; },
        clearSingle() { this.single = { product_id: null, sku: '', name: '' }; },
        searchRow(c) { fetch(api + encodeURIComponent(c.search.trim())).then(r => r.json()).then(d => { const u = this.used(c); c.results = d.filter(x => !u.includes(x.id)); }); },
        pickRow(c, r) { c.product_id = r.id; c.sku = r.sku; c.name = r.name; c.search = ''; c.results = []; if (!this.defaultKey || !this.filledCombos().some(x => x.key === this.defaultKey)) this.defaultKey = c.key; },
        clearRow(c) { c.product_id = null; c.sku = ''; c.name = ''; },
        filledCombos() { return this.combos.filter(c => c.product_id); },
        filledDefaultIndex() { const f = this.filledCombos(); const i = f.findIndex(c => c.key === this.defaultKey); return i < 0 ? 0 : i; },
        formatRp(n) { return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID'); },
        imgUrl(id) { const m = this.images.find(x => String(x.id) === String(id)); return m ? m.url : ''; },
    };
}
</script>
@endpush
