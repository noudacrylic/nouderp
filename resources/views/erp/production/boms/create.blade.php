@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="bomForm()">

    <div class="flex justify-between items-start mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 mb-1">
                <a href="{{ route('production.boms.index') }}" class="hover:text-blue-600">BOM</a>
                <span>›</span>
                <span>{{ isset($bom) ? $bom->bom_number : 'Buat BOM Baru' }}</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">{{ isset($bom) ? 'Edit BOM' : 'Buat BOM Baru' }}</h1>
        </div>
        <a href="{{ route('production.boms.index') }}" class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Kembali</a>
    </div>


    <form action="{{ isset($bom) ? route('production.boms.update', $bom->id) : route('production.boms.store') }}" method="POST"
          @submit="onFormSubmit($event)">
        @csrf
        @if(isset($bom)) @method('PUT') @endif

        <div class="grid grid-cols-12 gap-5">

            {{-- Kolom kiri --}}
            <div class="col-span-8 space-y-5">

                {{-- Sticky: Nama BOM + Jumlah Siklus --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sticky top-4 z-10">
                    <div class="bg-amber-50 border border-amber-100 rounded-xl px-3 py-2.5 mb-4 text-xs text-amber-700">
                        <strong>BOM ditulis per siklus.</strong> Bahan baku dan output diisi untuk 1 siklus produksi.
                        Untuk membuat 2 siklus sekaligus, isi jumlah siklus saat membuat Order Produksi.
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Nama BOM *</label>
                            <input type="text" name="name" value="{{ old('name', $bom->name ?? '') }}" required
                                   placeholder="Misal: BOM Produk A - Batch Normal"
                                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Jumlah Siklus Biasa</label>
                            <input type="number" name="typical_cycles" x-model="typicalCycles" min="1"
                                   @change="refreshScore()"
                                   placeholder="Misal: 10"
                                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                            <p class="text-[10px] text-gray-400 mt-1">Biasanya BOM ini dikerjakan berapa siklus sekali jalan?</p>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-gray-500 mb-1">Deskripsi</label>
                            <textarea name="description" rows="2" placeholder="Keterangan tambahan..."
                                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">{{ old('description', $bom->description ?? '') }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- Bahan Baku --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-gray-700">Bahan Baku (per Siklus)</h3>
                        <button type="button" @click="addMaterial()"
                                class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah Bahan</button>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(m, idx) in materials" :key="idx">
                            <div class="flex gap-3 items-start p-3 bg-gray-50 rounded-xl">
                                <div class="flex-1 relative" @click.outside="m.showDrop = false">
                                    <input type="text" x-model="m.productQuery"
                                           @input.debounce.300ms="searchProduct(idx, 'materials')"
                                           @focus="m.showDrop = true"
                                           placeholder="Cari produk bahan baku..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <input type="hidden" :name="`materials[${idx}][product_id]`" :value="m.product_id">
                                    <div x-show="m.showDrop && m.results.length > 0"
                                         class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                        <template x-for="p in m.results" :key="p.id">
                                            <div @click="selectProduct(idx, p, 'materials')"
                                                 class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                                <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="w-28">
                                    <input type="number" :name="`materials[${idx}][qty_per_cycle]`" x-model="m.qty_per_cycle"
                                           min="0.0001" step="0.0001" placeholder="Qty/siklus"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                </div>
                                <div class="w-24">
                                    <input type="text" :name="`materials[${idx}][unit]`" x-model="m.unit"
                                           placeholder="Satuan"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                </div>
                                <button type="button" @click="materials.splice(idx, 1)"
                                        class="p-2 text-red-400 hover:text-red-600 rounded-lg transition mt-0.5">✕</button>
                            </div>
                        </template>
                        <div x-show="materials.length === 0" class="text-xs text-gray-400 text-center py-4">
                            Klik "+ Tambah Bahan" untuk menambahkan bahan baku.
                        </div>
                    </div>
                </div>

                {{-- Output Produksi --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-gray-700">Output Produksi (per Siklus)</h3>
                        <button type="button" @click="addOutput()"
                                class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah Output</button>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(o, idx) in outputs" :key="idx">
                            <div class="flex gap-3 items-start p-3 bg-gray-50 rounded-xl">
                                <div class="flex-1 relative" @click.outside="o.showDrop = false">
                                    <input type="text" x-model="o.productQuery"
                                           @input.debounce.300ms="searchProduct(idx, 'outputs')"
                                           @focus="o.showDrop = true"
                                           placeholder="Cari produk output..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <input type="hidden" :name="`outputs[${idx}][product_id]`" :value="o.product_id">
                                    <div x-show="o.showDrop && o.results.length > 0"
                                         class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                        <template x-for="p in o.results" :key="p.id">
                                            <div @click="selectProduct(idx, p, 'outputs')"
                                                 class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                                <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <div class="w-24">
                                    <input type="number" :name="`outputs[${idx}][qty_per_cycle]`" x-model="o.qty_per_cycle"
                                           min="0.0001" step="0.0001" placeholder="Qty/siklus"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                </div>
                                <div class="w-28">
                                    <select :name="`outputs[${idx}][output_type]`" x-model="o.output_type"
                                            class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                        <option value="main">Utama</option>
                                        <option value="by_product">Sampingan</option>
                                    </select>
                                </div>
                                <div class="w-20">
                                    <input type="number" :name="`outputs[${idx}][percentage]`" x-model="o.percentage"
                                           min="0" max="100" step="0.01" placeholder="%"
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                </div>
                                <button type="button" @click="outputs.splice(idx, 1)"
                                        class="p-2 text-red-400 hover:text-red-600 rounded-lg transition mt-0.5">✕</button>
                            </div>
                        </template>
                        <div x-show="outputs.length === 0" class="text-xs text-gray-400 text-center py-4">
                            Klik "+ Tambah Output" untuk menambahkan produk yang dihasilkan.
                        </div>
                    </div>

                    {{-- Indikator total persentase: wajib 100% (proteksi distribusi biaya output) --}}
                    <div x-show="outputs.length > 0" class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                        <span class="text-gray-500">Total persentase output <span class="text-gray-400">(harus 100%)</span></span>
                        <span :class="outputsPercentageOk() ? 'text-green-600 font-bold' : 'text-red-600 font-bold'">
                            <span x-text="percentageTotal()"></span>%<span x-text="percentageHint()"></span>
                        </span>
                    </div>
                </div>

            </div>

            {{-- Kolom kanan: Score + Langkah + Simpan --}}
            <div class="col-span-4">
                <div class="sticky top-4 space-y-5">

                    {{-- Score Prioritas --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-bold text-gray-500">Score Prioritas</label>
                            <button type="button" @click="refreshScore()"
                                    class="text-[10px] text-blue-500 hover:text-blue-700 font-semibold">
                                ↻ Hitung
                            </button>
                        </div>
                        <div class="bg-gray-50 border border-gray-100 rounded-lg px-3 py-2.5 text-sm flex items-center gap-2">
                            <span class="font-black text-gray-800 text-xl" x-text="liveScore ?? '—'"></span>
                            <span class="text-xs text-gray-400" x-show="scoreLoading">menghitung...</span>
                            <span class="text-xs text-gray-400" x-show="!scoreLoading && liveScore === null">isi output utama dulu</span>
                        </div>
                    </div>

                    {{-- Langkah Produksi --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-bold text-gray-700 text-sm">Langkah Produksi</h3>
                            <button type="button" @click="addStep()"
                                    class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah</button>
                        </div>
                        <div class="space-y-2">
                            <template x-for="(s, idx) in steps" :key="idx">
                                <div class="flex gap-2 items-center">
                                    <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-black flex items-center justify-center flex-shrink-0"
                                          x-text="idx + 1"></span>
                                    <select :name="`steps[${idx}][department_id]`" x-model="s.department_id"
                                            @change="s.name = $event.target.options[$event.target.selectedIndex].text"
                                            class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                        <option value="">— Pilih Departemen —</option>
                                        @foreach($departments as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" @click="steps.splice(idx, 1)"
                                            class="text-red-400 hover:text-red-600 text-xs px-1">✕</button>
                                    <input type="hidden" :name="`steps[${idx}][name]`" :value="s.name">
                                    <input type="hidden" :name="`steps[${idx}][description]`" value="">
                                    <input type="hidden" :name="`steps[${idx}][estimated_hours]`" value="">
                                </div>
                            </template>
                            <div x-show="steps.length === 0" class="text-xs text-gray-400 text-center py-3">
                                Klik "+ Tambah" untuk menambahkan langkah produksi.
                            </div>
                        </div>
                    </div>

                    {{-- Auto Produksi --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <h3 class="font-bold text-gray-600 text-sm mb-3">Auto-Produksi</h3>
                        <label for="auto_production" class="flex items-start gap-3 cursor-pointer">
                            <input type="hidden" name="auto_production" value="0">
                            <input type="checkbox" id="auto_production" name="auto_production" value="1"
                                   {{ old('auto_production', $bom->auto_production ?? false) ? 'checked' : '' }}
                                   class="mt-0.5 w-4 h-4 rounded accent-blue-600 cursor-pointer flex-shrink-0">
                            <span class="flex-1">
                                <span class="block text-sm font-bold text-gray-700">Aktifkan</span>
                                <span class="block text-[11px] text-gray-500 mt-1 leading-relaxed">
                                    <strong>Produk ready:</strong> saat stok ≤ <em>min stock</em>, sistem otomatis membuat order produksi (siklus = <em>jumlah siklus biasa</em>).<br>
                                    <strong>Produk preorder:</strong> order produksi otomatis dibuat &amp; dikonfirmasi saat DP/uang muka di-post (bukan saat SO dikonfirmasi), siklus = qty pesanan. Output utama BOM preorder harus <em>qty per siklus = 1</em>.<br>
                                    Skor = otomatis. Hanya 1 BOM per produk utama yang boleh aktif.
                                </span>
                            </span>
                        </label>
                    </div>

                    {{-- Simpan --}}
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <h3 class="font-bold text-gray-600 text-sm mb-3">Simpan BOM</h3>
                        <button type="submit"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl text-sm font-bold transition">
                            {{ isset($bom) ? 'Perbarui BOM' : 'Simpan BOM' }}
                        </button>
                        <p x-show="submitError" x-cloak x-text="submitError"
                           class="text-red-600 text-xs font-semibold mt-2 text-center"></p>
                    </div>

                </div>
            </div>

        </div>
    </form>
</div>
@endsection

@php
    $initMaterials = isset($bom) ? $bom->materials->map(function($m) {
        return ['product_id' => $m->product_id, 'productQuery' => ($m->product?->sku ?? '') . ' - ' . ($m->product?->name ?? ''), 'qty_per_cycle' => $m->qty_per_cycle, 'unit' => $m->unit, 'results' => [], 'showDrop' => false];
    })->values()->all() : [];
    $initOutputs = isset($bom) ? $bom->outputs->map(function($o) {
        return ['product_id' => $o->product_id, 'productQuery' => ($o->product?->sku ?? '') . ' - ' . ($o->product?->name ?? ''), 'qty_per_cycle' => $o->qty_per_cycle, 'output_type' => $o->output_type, 'percentage' => $o->percentage, 'results' => [], 'showDrop' => false];
    })->values()->all() : [];
    $initSteps = isset($bom) ? $bom->steps->map(function($s) {
        return ['name' => $s->name, 'description' => $s->description, 'department_id' => $s->department_id, 'estimated_hours' => $s->estimated_hours];
    })->values()->all() : [];
@endphp

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function bomForm() {
    return {
        materials: @json($initMaterials),
        outputs: @json($initOutputs),
        steps: @json($initSteps),
        typicalCycles: {{ old('typical_cycles', $bom->typical_cycles ?? 1) }},
        liveScore: {{ isset($bom) && $bom->score ? $bom->score : 'null' }},
        scoreLoading: false,
        submitError: '',

        // ── Validasi total persentase output (wajib 100%, seperti Order Produksi) ──
        percentageTotal() {
            const sum = this.outputs.reduce((s, o) => s + (parseFloat(o.percentage) || 0), 0);
            return Math.round(sum * 100) / 100;
        },
        outputsAllFilled() {
            return this.outputs.every(o =>
                o.percentage !== '' && o.percentage !== null && o.percentage !== undefined
                && !isNaN(parseFloat(o.percentage)));
        },
        outputsPercentageOk() {
            if (this.outputs.length === 0) return true;
            return this.outputsAllFilled() && Math.abs(this.percentageTotal() - 100) < 0.01;
        },
        percentageHint() {
            if (this.outputs.length === 0) return '';
            if (!this.outputsAllFilled()) return ' — lengkapi semua persentase';
            const diff = Math.round((100 - this.percentageTotal()) * 100) / 100;
            if (diff > 0) return ` — kurang ${diff}%`;
            if (diff < 0) return ` — lebih ${Math.abs(diff)}%`;
            return ' ✓';
        },
        onFormSubmit(e) {
            this.submitError = '';
            if (!this.outputsPercentageOk()) {
                e.preventDefault();
                this.submitError = `Total persentase output harus 100% (saat ini ${this.percentageTotal()}%).`;
            }
        },

        addMaterial() { this.materials.push({ product_id: null, productQuery: '', qty_per_cycle: 1, unit: '', results: [], showDrop: false }); },
        addOutput()   { this.outputs.push({ product_id: null, productQuery: '', qty_per_cycle: 1, output_type: 'main', percentage: 100, results: [], showDrop: false }); },
        addStep()     { this.steps.push({ name: '', description: '', department_id: '', estimated_hours: '' }); },

        async searchProduct(idx, list) {
            const q = this[list][idx].productQuery;
            if (q.length < 2) { this[list][idx].results = []; return; }
            // Bundle tidak masuk produksi → kecualikan dari search bahan baku & output.
            const res = await fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}&exclude_bundle=1`);
            this[list][idx].results = await res.json();
            this[list][idx].showDrop = true;
        },

        selectProduct(idx, p, list) {
            this[list][idx].product_id   = p.id;
            this[list][idx].productQuery = `${p.sku} - ${p.name}`;
            this[list][idx].results      = [];
            this[list][idx].showDrop     = false;
            // Auto-isi satuan dengan satuan dasar produk saat bahan dipilih.
            if (list === 'materials' && p.base_unit) this[list][idx].unit = p.base_unit;
            if (list === 'outputs') this.refreshScore();
        },

        async refreshScore() {
            const mainOutput = this.outputs.find(o => o.output_type === 'main' && o.product_id);
            if (!mainOutput) { this.liveScore = null; return; }
            this.scoreLoading = true;
            try {
                const res = await fetch('/erp/production/boms/preview-score', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        typical_cycles: this.typicalCycles || 1,
                        outputs: this.outputs.map(o => ({
                            product_id: o.product_id,
                            qty_per_cycle: o.qty_per_cycle,
                            output_type: o.output_type,
                        })),
                    }),
                });
                const data = await res.json();
                this.liveScore = data.score;
            } catch (e) {}
            this.scoreLoading = false;
        },
    };
}
</script>
@endpush
