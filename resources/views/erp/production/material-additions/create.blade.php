@extends('layouts.erp')

@section('content')
@php
    // Encode all steps as JS-friendly array for Alpine dropdown
    $stepsJs = $activeSteps->map(function($step) {
        $order   = $step->productionOrder;
        $mainOut = $order->outputs->where('output_type', 'main')->first();
        $product = $mainOut?->product;
        return [
            'id'     => $step->id,
            'label'  => implode(' · ', array_filter([
                $order->order_number,
                $product?->name,
            ])),
            'status' => match($step->status) {
                'in_progress' => 'Sedang Dikerjakan',
                'paused'      => 'Di-Pause',
                'pending'     => 'Antre',
                default       => 'Menunggu',
            },
            'statusClass' => match($step->status) {
                'in_progress' => 'bg-indigo-100 text-indigo-700',
                'paused'      => 'bg-orange-100 text-orange-700',
                'pending'     => 'bg-amber-100 text-amber-700',
                default       => 'bg-gray-100 text-gray-600',
            },
            'dept'   => $step->department?->name ?? '',
            'search' => strtolower(implode(' ', array_filter([
                $order->order_number,
                $product?->sku,
                $product?->name,
                $step->name,
                $step->department?->name,
            ]))),
        ];
    })->values()->toArray();
@endphp

<div class="w-full px-6 py-4" x-data="materialAdditionForm({{ Js::from($stepsJs) }}, {{ $preSelectedStepId ?? 'null' }})">

    {{-- Header --}}
    <div class="flex justify-between items-start mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 mb-1">
                <a href="{{ route('production.material-additions.index') }}" class="hover:text-blue-600">Penambahan Bahan</a>
                <span>›</span><span>Tambah Baru</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">Tambah Bahan di Tengah Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Untuk penggantian komponen rusak atau kebutuhan bahan tambahan.</p>
        </div>
        <a href="{{ route('production.material-additions.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            ← Kembali
        </a>
    </div>


    <form action="{{ route('production.material-additions.store') }}" method="POST">
        @csrf

        <div class="grid grid-cols-12 gap-5">

            {{-- Kolom kiri --}}
            <div class="col-span-8 space-y-5">

                {{-- Pilih Task --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-3">Pilih Task Produksi</h3>

                    <div class="flex gap-3">
                        {{-- Filter Divisi — navigasi JS, tanpa nested form --}}
                        <select onchange="window.location.href='{{ route('production.material-additions.create') }}?department_id=' + this.value"
                                class="flex-shrink-0 border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                            <option value="">— Semua Divisi —</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" {{ $departmentId == $dept->id ? 'selected' : '' }}>
                                    {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>

                        {{-- Searchable Task Dropdown --}}
                        <div class="flex-1 relative" @click.outside="taskOpen = false">
                            <input type="hidden" name="production_order_step_id" :value="selectedStepId">

                            {{-- Trigger --}}
                            <button type="button"
                                    @click="taskOpen = !taskOpen"
                                    class="w-full flex items-center justify-between border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white hover:border-gray-300 transition text-left"
                                    :class="selectedStepId ? 'text-gray-800' : 'text-gray-400'">
                                <span class="truncate" x-text="selectedStepId ? selectedLabel : '— Pilih Task —'"></span>
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0 ml-2 transition-transform" :class="taskOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            {{-- Dropdown panel --}}
                            <div x-show="taskOpen"
                                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                                 class="absolute z-30 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden"
                                 style="display:none;">

                                {{-- Search dalam dropdown --}}
                                <div class="p-2 border-b border-gray-100">
                                    <input type="text" x-model="taskSearch" x-ref="taskInput"
                                           @keydown.escape="taskOpen = false"
                                           placeholder="Cari nomor, SKU, produk..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400">
                                </div>

                                {{-- Daftar opsi --}}
                                <div class="max-h-56 overflow-y-auto">
                                    <template x-for="opt in filteredSteps" :key="opt.id">
                                        <button type="button"
                                                @click="selectStep(opt)"
                                                class="w-full flex items-center gap-2 px-3 py-2 hover:bg-blue-50 transition text-left"
                                                :class="selectedStepId == opt.id ? 'bg-blue-50' : ''">
                                            <span class="flex-1 text-xs font-medium text-gray-800 truncate" x-text="opt.label"></span>
                                            <span class="text-[10px] text-gray-400 flex-shrink-0" x-text="opt.dept"></span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded font-bold flex-shrink-0"
                                                  :class="opt.statusClass" x-text="opt.status"></span>
                                        </button>
                                    </template>
                                    <div x-show="filteredSteps.length === 0"
                                         class="px-3 py-6 text-center text-xs text-gray-400">
                                        Tidak ada task yang cocok
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Tambahan Bahan Baku --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-3">
                        <div>
                            <h3 class="font-bold text-gray-700">Tambahan Bahan Baku</h3>
                            <p class="text-[10px] text-gray-400 mt-0.5">Opsional. Boleh dikosongkan bila yang ditambahkan hanya biaya.</p>
                        </div>
                        <button type="button" @click="addRow()"
                                class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-1.5 rounded-lg transition">
                            + Tambah Baris
                        </button>
                    </div>

                    {{-- Header kolom --}}
                    <div class="flex gap-2 px-1 mb-1 text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                        <div class="flex-1">Bahan Baku</div>
                        <div class="w-28 text-center">Jumlah</div>
                        <div class="w-16 text-center">Satuan</div>
                        <div class="w-7"></div>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(row, idx) in rows" :key="row.key">
                            <div class="flex gap-2 items-center">

                                {{-- Produk --}}
                                <div class="flex-1 relative" @click.outside="row.showDrop = false">
                                    <input type="text"
                                           x-model="row.productQuery"
                                           @input.debounce.300ms="searchProduct(idx)"
                                           @focus="row.showDrop = true"
                                           placeholder="Cari produk / bahan..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white"
                                           :class="row.product_id ? 'border-green-300 bg-green-50' : ''">
                                    <input type="hidden" :name="`items[${idx}][product_id]`" :value="row.product_id">

                                    {{-- Dropdown hasil --}}
                                    <div x-show="row.showDrop && row.results.length > 0"
                                         class="absolute z-20 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-xl shadow-lg max-h-44 overflow-y-auto">
                                        <template x-for="p in row.results" :key="p.id">
                                            <button type="button" @click="selectProduct(idx, p)"
                                                    class="w-full text-left px-3 py-2 hover:bg-blue-50 text-sm border-b border-gray-50 last:border-0">
                                                <span class="font-medium text-gray-800" x-text="p.name"></span>
                                                <span class="text-xs text-gray-400 ml-1" x-show="p.sku" x-text="'· ' + p.sku"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                {{-- Qty + kalkulator --}}
                                <div class="w-28 flex gap-1">
                                    <input type="number" step="0.0001" min="0.0001"
                                           :name="`items[${idx}][qty_requested]`"
                                           x-model="row.qty"
                                           :required="!!row.product_id"
                                           placeholder="0"
                                           class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <button type="button" @click="openCalc(idx)" title="Kalkulator"
                                            class="flex-shrink-0 px-2 py-2 bg-amber-50 hover:bg-amber-100 text-amber-600 rounded-lg border border-amber-200 text-xs">
                                        🧮
                                    </button>
                                </div>

                                {{-- Satuan (readonly, dari produk) --}}
                                <div class="w-16">
                                    <input type="text" :name="`items[${idx}][unit]`"
                                           x-model="row.unit"
                                           readonly
                                           class="w-full border border-gray-100 rounded-lg px-2 py-2 text-sm text-center bg-gray-50 text-gray-500 cursor-default"
                                           placeholder="—">
                                </div>

                                {{-- Hapus --}}
                                <button type="button" @click="removeRow(idx)"
                                        x-show="rows.length > 1"
                                        class="w-7 h-8 flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition flex-shrink-0">
                                    ✕
                                </button>
                                <div x-show="rows.length <= 1" class="w-7"></div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Biaya Tambahan --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-3">
                        <div>
                            <h3 class="font-bold text-gray-700">Biaya Tambahan</h3>
                            <p class="text-[10px] text-gray-400 mt-0.5">Pengeluaran kas langsung. Jurnal Dr.WIP / Cr.Kas otomatis.</p>
                        </div>
                        <button type="button" @click="addCost()"
                                class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold px-3 py-1.5 rounded-lg transition">
                            + Tambah Biaya
                        </button>
                    </div>

                    <div x-show="costs.length === 0" class="text-xs text-gray-400 text-center py-3">
                        Opsional. Tambah jika ada pengeluaran kas untuk keperluan produksi ini.
                    </div>

                    <div x-show="costs.length > 0" class="flex gap-2 px-1 mb-1 text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                        <div class="flex-1">Keterangan</div>
                        <div class="w-36 text-center">Jumlah (Rp)</div>
                        <div class="w-44">Kas / Bank</div>
                        <div class="w-7"></div>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(c, idx) in costs" :key="idx">
                            <div class="flex gap-2 items-center">
                                <input type="text" :name="`costs[${idx}][description]`" x-model="c.description"
                                       placeholder="Cth: Baut M3×15 20 pcs"
                                       class="flex-1 border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                <input type="number" :name="`costs[${idx}][amount]`" x-model="c.amount"
                                       min="0.01" step="0.01" placeholder="0"
                                       class="w-36 border border-gray-200 rounded-lg px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                <select :name="`costs[${idx}][cash_account_id]`" x-model="c.cash_account_id"
                                        class="w-44 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <option value="">— Pilih —</option>
                                    @foreach($cashAccounts as $acc)
                                        <option value="{{ $acc->id }}">{{ $acc->code }} · {{ $acc->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" @click="costs.splice(idx, 1)"
                                        class="w-7 h-8 flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition flex-shrink-0">
                                    ✕
                                </button>
                            </div>
                        </template>
                    </div>

                    <div x-show="costs.length > 0" class="mt-3 pt-3 border-t border-gray-100 flex justify-end text-sm">
                        <span class="text-gray-500 mr-2">Total Biaya:</span>
                        <span class="font-bold text-gray-800"
                              x-text="'Rp ' + costs.reduce((s, c) => s + (parseFloat(c.amount) || 0), 0).toLocaleString('id-ID')"></span>
                    </div>
                </div>

            </div>

            {{-- Kolom kanan --}}
            <div class="col-span-4 space-y-4">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sticky top-4">
                    <h3 class="font-bold text-gray-700 mb-4">Catatan</h3>
                    <textarea name="notes" rows="4"
                              placeholder="Alasan penambahan bahan, nomor laporan kerusakan, dll..."
                              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300 resize-none">{{ old('notes') }}</textarea>

                    <div class="mt-4 p-3 bg-amber-50 border border-amber-100 rounded-xl text-xs text-amber-700">
                        <p class="font-bold mb-1">Perhatian</p>
                        <p x-show="hasMaterial">Stok bahan akan langsung dikurangi saat disimpan dan dicatat ke jurnal WIP.</p>
                        <p x-show="hasCost" :class="hasMaterial ? 'mt-1' : ''">Biaya tambahan langsung mengurangi kas dan masuk WIP produksi.</p>
                        <p x-show="!hasMaterial && !hasCost">Isi bahan baku, biaya tambahan, atau keduanya. Semuanya langsung dicatat ke jurnal WIP.</p>
                    </div>

                    <button type="submit"
                            :disabled="!canSubmit"
                            :class="canSubmit
                                ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer'
                                : 'bg-blue-300 cursor-not-allowed'"
                            class="w-full mt-4 py-2.5 text-white text-sm font-bold rounded-xl transition"
                            x-text="hasMaterial ? 'Simpan Penambahan Bahan' : (hasCost ? 'Simpan Biaya Tambahan' : 'Simpan Penambahan')">
                    </button>
                </div>
            </div>

        </div>
    </form>

    {{-- ===== MODAL KALKULATOR ===== --}}
    <div x-show="calc !== null"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
         @click.self="calc = null"
         style="display:none;">

        <div x-show="calc !== null"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">

            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-gray-800 text-sm">🧮 Kalkulator Bahan</h3>
                    <p class="text-xs text-gray-400 mt-0.5">Hitung jumlah lembaran / material yang dibutuhkan</p>
                </div>
                <button type="button" @click="calc = null"
                        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center text-sm">✕</button>
            </div>

            <div class="p-5 space-y-4">

                {{-- Ukuran bahan baku (default 122×244) --}}
                <div>
                    <label class="block text-xs font-black text-gray-600 mb-1 uppercase tracking-wider">Ukuran 1 Lembar Bahan Baku</label>
                    <p class="text-[10px] text-gray-400 mb-2">Luas Bahan = <span x-text="calc ? (calc.mat_w * calc.mat_h).toLocaleString() : '—'"></span> <span x-text="calc?.dim_unit"></span>²</p>
                    <div class="flex items-center gap-2">
                        <div class="flex-1">
                            <label class="text-[10px] text-gray-400">Panjang</label>
                            <input type="number" step="0.01" min="0" x-model.number="calc.mat_w" @input="calcResult()"
                                   class="mt-0.5 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300">
                        </div>
                        <span class="text-gray-400 text-lg mt-4">×</span>
                        <div class="flex-1">
                            <label class="text-[10px] text-gray-400">Lebar</label>
                            <input type="number" step="0.01" min="0" x-model.number="calc.mat_h" @input="calcResult()"
                                   class="mt-0.5 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300">
                        </div>
                        <div class="flex-1">
                            <label class="text-[10px] text-gray-400">Satuan</label>
                            <input type="text" x-model="calc.dim_unit"
                                   class="mt-0.5 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300" placeholder="cm">
                        </div>
                    </div>
                </div>

                {{-- Ukuran kebutuhan --}}
                <div>
                    <label class="block text-xs font-black text-gray-600 mb-1 uppercase tracking-wider">Luas yang Dibutuhkan</label>
                    <p class="text-[10px] text-gray-400 mb-2">Luas Kebutuhan = <span x-text="calc ? (calc.use_w * calc.use_h).toLocaleString() : '—'"></span> <span x-text="calc?.dim_unit"></span>²</p>
                    <div class="flex items-center gap-2">
                        <div class="flex-1">
                            <label class="text-[10px] text-gray-400">Panjang</label>
                            <input type="number" step="0.01" min="0" x-model.number="calc.use_w" @input="calcResult()"
                                   class="mt-0.5 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300" placeholder="0">
                        </div>
                        <span class="text-gray-400 text-lg mt-4">×</span>
                        <div class="flex-1">
                            <label class="text-[10px] text-gray-400">Lebar</label>
                            <input type="number" step="0.01" min="0" x-model.number="calc.use_h" @input="calcResult()"
                                   class="mt-0.5 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300" placeholder="0">
                        </div>
                        <div class="flex-1 invisible"></div>
                    </div>
                </div>

                {{-- Hasil otomatis --}}
                <div class="rounded-xl p-4 border transition"
                     :class="calc.result ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-100'">
                    <div class="text-xs font-black uppercase tracking-wider mb-3"
                         :class="calc.result ? 'text-amber-600' : 'text-gray-400'">Hasil Kalkulasi</div>
                    <div class="text-center">
                        <div class="text-[10px] text-gray-400 mb-1">Jumlah Lembar Dibutuhkan</div>
                        <div class="text-4xl font-black mb-1" :class="calc.result ? 'text-blue-700' : 'text-gray-300'">
                            <span x-text="calc.result ?? '—'"></span>
                            <span x-show="calc.result" class="text-lg font-semibold text-blue-500"> Lembar</span>
                        </div>
                        <div class="text-[10px] text-gray-400" x-show="calc.result">
                            = <span x-text="(calc.use_w * calc.use_h).toLocaleString()"></span> <span x-text="calc.dim_unit"></span>² ÷ <span x-text="(calc.mat_w * calc.mat_h).toLocaleString()"></span> <span x-text="calc.dim_unit"></span>²
                        </div>
                        <div class="text-[10px] text-gray-400 mt-1" x-show="!calc.result">
                            Isi ukuran kebutuhan untuk melihat hasil
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="button" @click="useCalcResult()"
                            :disabled="!calc.result"
                            :class="calc.result ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer' : 'bg-blue-300 cursor-not-allowed'"
                            class="flex-1 py-2.5 text-white text-sm font-bold rounded-xl transition">
                        Gunakan Hasil (<span x-text="calc.result ?? '—'"></span> lembar)
                    </button>
                    <button type="button" @click="calc = null"
                            class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function materialAdditionForm(stepsData, preSelectedStepId) {
    return {
        // Task dropdown
        allSteps: stepsData,
        taskOpen: false,
        taskSearch: '',
        selectedStepId: null,
        selectedLabel: '',

        // Material rows
        rows: [],
        rowKey: 0,

        // Biaya tambahan
        costs: [],

        // Calculator
        calc: null,
        calcTargetIdx: null,

        get filteredSteps() {
            if (!this.taskSearch) return this.allSteps;
            const q = this.taskSearch.toLowerCase();
            return this.allSteps.filter(s => s.search.includes(q));
        },

        get hasMaterial() {
            return this.rows.some(r => r.product_id && parseFloat(r.qty) > 0);
        },

        get hasCost() {
            return this.costs.some(c => c.description && parseFloat(c.amount) > 0 && c.cash_account_id);
        },

        // Penambahan boleh isi bahan saja, biaya saja, atau keduanya — asal tidak kosong.
        get canSubmit() {
            return !!this.selectedStepId && (this.hasMaterial || this.hasCost);
        },

        selectStep(opt) {
            this.selectedStepId = opt.id;
            this.selectedLabel  = opt.label + (opt.dept ? ' · ' + opt.dept : '') + ' [' + opt.status + ']';
            this.taskOpen = false;
            this.taskSearch = '';
        },

        init() {
            this.addRow();
            if (preSelectedStepId) {
                const found = this.allSteps.find(s => s.id == preSelectedStepId);
                if (found) this.selectStep(found);
            }
            this.$watch('taskOpen', val => {
                if (val) this.$nextTick(() => this.$refs.taskInput?.focus());
            });
        },

        addRow() {
            this.rows.push({ key: this.rowKey++, product_id: null, productQuery: '', results: [], showDrop: false, qty: '', unit: '', notes: '' });
        },

        removeRow(idx) {
            if (this.rows.length > 1) this.rows.splice(idx, 1);
        },

        async searchProduct(idx) {
            const q = this.rows[idx].productQuery;
            if (q.length < 2) { this.rows[idx].results = []; return; }
            const res  = await fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`);
            const data = await res.json();
            this.rows[idx].results  = data;
            this.rows[idx].showDrop = true;
        },

        async selectProduct(idx, p) {
            this.rows[idx].product_id   = p.id;
            this.rows[idx].productQuery = p.name + (p.sku ? ' (' + p.sku + ')' : '');
            this.rows[idx].showDrop     = false;
            this.rows[idx].results      = [];

            // Fetch satuan dasar dari produk
            try {
                const r = await fetch(`/erp/api/products/${p.id}/units`);
                const d = await r.json();
                this.rows[idx].unit = d.base_unit ?? '';
            } catch(e) { this.rows[idx].unit = ''; }
        },

        addCost() {
            this.costs.push({ description: '', amount: '', cash_account_id: '' });
        },

        openCalc(idx) {
            this.calcTargetIdx = idx;
            this.calc = { mat_w: 122, mat_h: 244, use_w: null, use_h: null,
                          dim_unit: 'cm', result: null };
            this.calcResult();
        },

        calcResult() {
            const c = this.calc;
            if (!c.mat_w || !c.mat_h || !c.use_w || !c.use_h) {
                c.result = null; return;
            }
            const sheetArea = c.mat_w * c.mat_h;
            const usageArea = c.use_w * c.use_h;
            c.result = sheetArea > 0 ? Math.round((usageArea / sheetArea) * 1000) / 1000 : null;
        },

        useCalcResult() {
            if (this.calc?.result && this.calcTargetIdx !== null)
                this.rows[this.calcTargetIdx].qty = this.calc.result;
            this.calc = null;
        },
    };
}
</script>
@endpush
@endsection
