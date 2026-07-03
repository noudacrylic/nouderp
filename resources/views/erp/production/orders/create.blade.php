@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="orderForm()">

    <div class="flex justify-between items-start mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 mb-1">
                <a href="{{ route('production.orders.index') }}" class="hover:text-blue-600">Order Produksi</a>
                <span>›</span><span>Buat Order Baru</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">Buat Order Produksi</h1>
        </div>
        <a href="{{ route('production.orders.index') }}" class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Kembali</a>
    </div>


    <form action="{{ route('production.orders.store') }}" method="POST" enctype="multipart/form-data"
          @submit="onFormSubmit($event)">
        @csrf

        @if($errors->any() || session('error'))
            <div class="mb-5 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm">
                <div class="font-bold mb-1">Order produksi gagal disimpan:</div>
                <div class="text-[11px] text-red-500 mt-1.5">Data yang sudah diisi tetap dipertahankan di bawah.</div>
            </div>
        @endif
        <div class="grid grid-cols-12 gap-5">

            {{-- Kolom Kiri --}}
            <div class="col-span-8 space-y-5">

                {{-- Tipe & Info Dasar --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-3">Tipe & Info Dasar</h3>

                    {{-- Baris 1: Tujuan | Tanggal | Gudang --}}
                    <div class="grid grid-cols-3 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Tujuan Produksi *</label>
                            <select name="type" x-model="type"
                                    class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                <option value="ready_stock">Ready Stock</option>
                                <option value="custom">Custom / Preorder</option>
                                <option value="repair">Perbaikan</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Tanggal Produksi *</label>
                            <input type="date" name="production_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Gudang *</label>
                            @if(!empty($lockWarehouse))
                                {{-- OP perbaikan: gudang dikunci ke gudang sumber (garansi/retur) --}}
                                @php $lockedWh = $warehouses->firstWhere('id', $defaultWarehouseId); @endphp
                                <input type="hidden" name="warehouse_id" value="{{ $defaultWarehouseId }}">
                                <div class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm text-gray-600">
                                    {{ $lockedWh?->name ?? '—' }}
                                    <span class="block text-[10px] text-gray-400">Mengikuti gudang dokumen sumber perbaikan (tidak bisa diubah).</span>
                                </div>
                            @else
                                <select name="warehouse_id" required
                                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    <option value="">— Pilih Gudang —</option>
                                    @foreach($warehouses as $wh)
                                        <option value="{{ $wh->id }}" @selected(old('warehouse_id', $defaultWarehouseId) == $wh->id)>{{ $wh->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    </div>

                    {{-- Baris 2 conditional --}}
                    {{-- Custom: SO (live search) --}}
                    <div x-show="type === 'custom'" x-transition class="mb-3">
                        <label class="block text-xs font-bold text-gray-500 mb-1">Sales Order *</label>
                        <input type="hidden" name="sales_order_id" :value="salesOrderId">
                        <input type="hidden" name="so_label" :value="salesOrderLabel">
                        <input type="hidden" name="so_customer" :value="salesOrderCustomer">

                        <template x-if="!salesOrderId">
                            <div class="relative" @click.outside="salesOrderDrop = false">
                                <input type="text" x-model="salesOrderQuery"
                                       @input.debounce.300ms="searchSalesOrders()"
                                       @focus="searchSalesOrders(); salesOrderDrop = true"
                                       placeholder="Cari SO: nomor, customer, SKU, atau nama produk..."
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-400">
                                <div x-show="salesOrderDrop && salesOrderResults.length > 0"
                                     class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-30 max-h-80 overflow-y-auto">
                                    <template x-for="so in salesOrderResults" :key="so.id">
                                        <div @click="selectSalesOrder(so)"
                                             class="px-3 py-2.5 hover:bg-purple-50 cursor-pointer border-b border-gray-100 last:border-0">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-bold text-blue-600 text-sm" x-text="so.order_number"></span>
                                                <span class="text-[10px] text-gray-400" x-text="so.order_date"></span>
                                            </div>
                                            <div class="text-xs text-gray-600 mt-0.5" x-text="so.customer_name"></div>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <template x-for="item in so.items" :key="item.product_id">
                                                    <span class="inline-flex items-center gap-1 text-[10px] bg-gray-100 px-1.5 py-0.5 rounded">
                                                        <span class="font-bold text-gray-700" x-text="item.sku"></span>
                                                        <span class="text-gray-500">·</span>
                                                        <span :class="item.qty_remaining > 0 ? 'text-purple-600 font-bold' : 'text-gray-400 line-through'">
                                                            <span x-text="item.qty_remaining"></span>/<span x-text="item.qty_ordered"></span>
                                                        </span>
                                                    </span>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <div x-show="salesOrderDrop && salesOrderResults.length === 0 && salesOrderQuery.trim() !== ''"
                                     class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-30 px-3 py-2.5 text-xs text-gray-400">
                                    Tidak ada SO dengan produk preorder yang cocok.
                                </div>
                            </div>
                        </template>

                        <template x-if="salesOrderId">
                            <div class="flex items-center gap-3 border border-purple-300 bg-purple-50 rounded-lg px-3 py-2">
                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-purple-800 text-sm" x-text="salesOrderLabel"></div>
                                    <div class="text-[10px] text-purple-600" x-text="salesOrderCustomer"></div>
                                </div>
                                <button type="button" @click="clearSalesOrder()"
                                        class="text-[10px] text-red-500 hover:text-red-700 font-bold">Ganti SO ✕</button>
                            </div>
                        </template>

                        <p class="text-[10px] text-purple-500 mt-0.5">Hanya SO dengan produk <strong>preorder</strong>. Output akan di-cap sesuai sisa yang belum diproduksi.</p>
                    </div>

                    {{-- Repair: Sumber + Ref (1 baris) --}}
                    <div x-show="type === 'repair'" x-transition class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">Sumber Perbaikan *</label>
                            <select name="repair_source_type" x-model="repairSource"
                                    class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
                                <option value="">— Pilih Sumber —</option>
                                <option value="adjustment">Penyesuaian Stok</option>
                                <option value="return">Retur Pelanggan</option>
                                <option value="warranty">Garansi</option>
                            </select>
                        </div>
                        <div x-show="repairSource" x-transition>
                            <label class="block text-xs font-bold text-gray-500 mb-1">
                                Nomor
                                <span x-text="repairSource === 'adjustment' ? 'Penyesuaian' : repairSource === 'return' ? 'Retur' : 'Garansi'"></span>
                            </label>
                            {{-- Hidden inputs untuk submit --}}
                            <input type="hidden" name="repair_source_ref" x-model="repairSourceRef">
                            <input type="hidden" name="repair_source_id"  x-model="repairSourceId">
                            {{-- Search dropdown --}}
                            <div class="relative" @click.outside="repairSourceDrop = false">
                                <input type="text" x-model="repairSourceQuery"
                                       @input.debounce.400ms="searchRepairSource()"
                                       @focus="if (repairSourceResults.length) repairSourceDrop = true"
                                       :placeholder="'Cari nomor ' + (repairSource === 'adjustment' ? 'penyesuaian' : repairSource === 'return' ? 'retur' : 'garansi') + '...'"
                                       :class="repairSourceRef ? 'border-orange-300 bg-orange-50' : 'border-gray-200 bg-white'"
                                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
                                <span x-show="repairSourceRef"
                                      class="absolute right-2 top-1/2 -translate-y-1/2 text-orange-400 cursor-pointer hover:text-red-500 text-xs font-bold"
                                      @click="clearRepairSource()">✕</span>
                                <div x-show="repairSourceDrop && repairSourceResults.length > 0"
                                     class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-30 max-h-48 overflow-y-auto">
                                    <template x-for="doc in repairSourceResults" :key="doc.id">
                                        <div @click="selectRepairSource(doc)"
                                             class="px-3 py-2 text-sm hover:bg-orange-50 cursor-pointer border-b border-gray-100 last:border-0 flex justify-between items-center">
                                            <span class="font-bold text-gray-800" x-text="doc.label"></span>
                                            <span class="text-[10px] text-gray-400 ml-2 flex-shrink-0"
                                                  x-text="doc.items.length + ' produk'"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Produk yang Diperbaiki (muncul setelah referensi dipilih) --}}
                    <div x-show="type === 'repair' && repairItems.length > 0" x-transition class="mb-3">
                        <div class="bg-orange-50 border border-orange-100 rounded-xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-xs font-bold text-orange-700 flex items-center gap-1.5">
                                    <span class="w-4 h-4 bg-orange-200 text-orange-700 rounded flex items-center justify-center text-[9px] font-black">R</span>
                                    Produk yang Diperbaiki
                                </h4>
                                <span class="text-[10px] text-orange-400">Dari referensi terpilih · tidak dapat diubah manual</span>
                            </div>
                            <div class="space-y-2">
                                <template x-for="(item, idx) in repairItems" :key="idx">
                                    <div class="flex items-center gap-3 bg-white rounded-lg px-3 py-2 border border-orange-100">
                                        <div class="flex-1 min-w-0">
                                            <span class="font-bold text-blue-600 text-xs" x-text="item.sku"></span>
                                            <span class="text-gray-700 text-xs ml-1" x-text="item.name"></span>
                                        </div>
                                        <span class="text-sm font-bold text-gray-700 w-14 text-right flex-shrink-0" x-text="item.qty"></span>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full flex-shrink-0"
                                              :class="item.output_type === 'main' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'"
                                              x-text="item.output_type === 'main' ? 'Utama' : 'Sampingan (' + item.percentage.toFixed(1) + '%)'"></span>
                                        {{-- Hidden inputs --}}
                                        <input type="hidden" :name="`repair_items[${idx}][product_id]`" :value="item.product_id">
                                        <input type="hidden" :name="`repair_items[${idx}][qty]`"         :value="item.qty">
                                        <input type="hidden" :name="`repair_items[${idx}][output_type]`" :value="item.output_type">
                                        <input type="hidden" :name="`repair_items[${idx}][percentage]`"  :value="item.percentage">
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Catatan --}}
                    <div>
                        <label class="block text-xs font-bold text-gray-500 mb-1">Catatan</label>
                        <input type="text" name="notes" placeholder="Catatan tambahan..."
                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                    </div>
                </div>

                {{-- BOM (disembunyikan untuk repair) --}}
                <div x-show="type !== 'repair'" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button type="button" @click="bomOpen = !bomOpen"
                            class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-gray-50 transition">
                        <span class="font-bold text-gray-700 text-sm flex items-center gap-2">
                            <span class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center text-[10px] font-black">B</span>
                            Gunakan BOM
                            <span x-show="selectedBomId" class="text-[10px] bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full font-bold" x-text="bomLabel"></span>
                        </span>
                        <span class="text-gray-400 text-sm transition-transform" :class="bomOpen ? 'rotate-180' : ''" >▾</span>
                    </button>
                    <div x-show="bomOpen" x-transition class="px-5 pb-5 border-t border-gray-100">
                        <div class="grid grid-cols-2 gap-4 pt-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1">BOM</label>
                                <input type="hidden" name="bom_id" :value="selectedBomId">

                                {{-- Live search (filter client-side dari daftar BOM) --}}
                                <template x-if="!selectedBomId">
                                    <div class="relative" @click.outside="bomDrop = false">
                                        <input type="text" x-model="bomQuery"
                                               @input="searchBoms()"
                                               @focus="searchBoms(); bomDrop = true"
                                               placeholder="Cari BOM: nomor atau nama... (kosongkan = Tanpa BOM)"
                                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                        <div x-show="bomDrop && bomResults.length > 0"
                                             class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-30 max-h-60 overflow-y-auto">
                                            <template x-for="b in bomResults" :key="b.id">
                                                <div @click="selectBom(b)"
                                                     class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 flex items-center justify-between gap-2">
                                                    <span class="min-w-0">
                                                        <span class="font-bold text-blue-600" x-text="b.bom_number"></span>
                                                        <span class="text-gray-600 ml-1" x-text="b.name"></span>
                                                    </span>
                                                    <span class="text-[10px] text-gray-400 flex-shrink-0"
                                                          x-text="b.typical_cycles + ' siklus'"></span>
                                                </div>
                                            </template>
                                        </div>
                                        <div x-show="bomDrop && bomResults.length === 0 && bomQuery.trim() !== ''"
                                             class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-30 px-3 py-2.5 text-xs text-gray-400">
                                            Tidak ada BOM yang cocok.
                                        </div>
                                    </div>
                                </template>

                                <template x-if="selectedBomId">
                                    <div class="flex items-center gap-3 border border-blue-300 bg-blue-50 rounded-lg px-3 py-2">
                                        <div class="flex-1 min-w-0">
                                            <div class="font-bold text-blue-800 text-sm truncate" x-text="bomLabel"></div>
                                            <div class="text-[10px] text-blue-600 truncate" x-text="bomName"></div>
                                        </div>
                                        <button type="button" @click="clearBom()"
                                                class="text-[10px] text-red-500 hover:text-red-700 font-bold flex-shrink-0">Ganti ✕</button>
                                    </div>
                                </template>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 mb-1">Jumlah Siklus</label>
                                <input type="text" inputmode="decimal" name="planned_cycles" x-model="cycles"
                                       @input="cycles = String(cycles).replace(',', '.').replace(/[^0-9.]/g,'')"
                                       min="0.0001" step="0.0001" value="1"
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                            </div>
                        </div>
                        <div x-show="loading" class="text-xs text-gray-400 text-center py-2 mt-2">Mengambil data BOM...</div>
                        <p class="text-[10px] text-gray-400 mt-2">Material, output, langkah, dan instruksi kerja akan diisi otomatis dari BOM.</p>
                    </div>
                </div>

                {{-- Kalkulator Produk Custom (hanya tipe custom) --}}
                <div x-show="type === 'custom'"
                     class="bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 rounded-2xl shadow-sm p-4 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-bold text-emerald-800 text-sm">🧮 Kalkulator Produk Custom</h3>
                        <p class="text-[11px] text-emerald-600 mt-0.5">Hitung kebutuhan bahan baku dari potongan lembar; hasilnya menambah material, output &amp; deskripsi.</p>
                    </div>
                    <button type="button" @click="openCalc()"
                            class="flex-shrink-0 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold px-4 py-2.5 rounded-xl transition">
                        Buka Kalkulator
                    </button>
                </div>

                {{-- Material (disembunyikan untuk repair) --}}
                <div x-show="type !== 'repair'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-gray-700">Material yang Digunakan</h3>
                        <button type="button" @click="addMaterial()"
                                class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah Material</button>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(m, idx) in materials" :key="idx">
                            <div class="flex gap-3 items-start p-3 bg-gray-50 rounded-xl">
                                <div class="flex-1 relative" @click.outside="m.showDrop = false">
                                    <input type="text" x-model="m.productQuery"
                                           @input.debounce.300ms="searchProduct(idx, 'materials')"
                                           @focus="m.showDrop = true"
                                           placeholder="Cari produk..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <input type="hidden" :name="`materials[${idx}][product_id]`" :value="m.product_id">
                                    <input type="hidden" :name="`materials[${idx}][label]`" :value="m.productQuery">
                                    <input type="hidden" :name="`materials[${idx}][unit_options]`" :value="JSON.stringify(m.unitOptions || [])">
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
                                <input type="text" inputmode="decimal" :name="`materials[${idx}][qty_required]`" x-model="m.qty_required"
                                       @input="m.qty_required = String(m.qty_required).replace(',', '.').replace(/[^0-9.]/g,'')"
                                       min="0.0001" step="0.0001" placeholder="Qty"
                                       class="w-28 border border-gray-200 rounded-lg px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                {{-- Satuan: auto base_unit saat pilih produk, tetap bisa pilih satuan lain --}}
                                <select :name="`materials[${idx}][unit]`" x-model="m.unit"
                                        class="w-28 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <option value="">— Satuan —</option>
                                    <template x-for="u in unitOptionsFor(m)" :key="u">
                                        <option :value="u" x-text="u"></option>
                                    </template>
                                </select>
                                <button type="button" @click="materials.splice(idx, 1)"
                                        class="p-2 text-red-400 hover:text-red-600 rounded-lg transition mt-0.5">✕</button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Output (disembunyikan untuk repair) --}}
                <div x-show="type !== 'repair'" id="outputAnchor" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-gray-700">Output yang Dihasilkan</h3>
                        <button type="button" @click="addOutput()"
                                class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah Output</button>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(o, idx) in outputs" :key="idx">
                            <div class="p-3 bg-gray-50 rounded-xl">
                                <div class="flex gap-3 items-start">
                                    <div class="flex-1 relative" @click.outside="o.showDrop = false">
                                        {{-- Utama: free-search. Sampingan: live-search terbatas daftar Pengaturan Produk Sampingan. --}}
                                        <template x-if="o.output_type === 'main'">
                                            <div>
                                                <input type="text" x-model="o.productQuery"
                                                       @input.debounce.300ms="searchProduct(idx, 'outputs')"
                                                       @focus="o.showDrop = true"
                                                       :readonly="o.fromSo"
                                                       :class="o.fromSo ? 'bg-purple-50 border-purple-200 text-purple-800 cursor-not-allowed' : 'bg-white border-gray-200'"
                                                       placeholder="Cari produk output..."
                                                       class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                <div x-show="!o.fromSo && o.showDrop && o.results.length > 0"
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
                                        </template>
                                        <template x-if="o.output_type === 'by_product'">
                                            <div>
                                                <input type="text" x-model="o.productQuery"
                                                       @input="o.showDrop = true" @focus="o.showDrop = true"
                                                       placeholder="Cari produk sampingan..."
                                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                                <div x-show="o.showDrop && filteredByproducts(o).length > 0"
                                                     class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                                    <template x-for="bp in filteredByproducts(o)" :key="bp.id">
                                                        <div @click="pickByproduct(idx, bp)"
                                                             class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                            <span class="text-gray-700" x-text="bp.label"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                        <input type="hidden" :name="`outputs[${idx}][product_id]`" :value="o.product_id">
                                        <input type="hidden" :name="`outputs[${idx}][label]`" :value="o.productQuery">
                                        <input type="hidden" :name="`outputs[${idx}][unit_percentage]`" :value="o.unit_percentage ?? ''">
                                        <p x-show="o.output_type === 'by_product' && byproducts.length === 0"
                                           class="text-[10px] text-amber-600 mt-1">Belum ada produk sampingan terdaftar. Tambahkan di Pengaturan Produksi.</p>
                                    </div>
                                    <input type="text" inputmode="decimal" :name="`outputs[${idx}][qty_planned]`" x-model="o.qty_planned"
                                           @input="o.qty_planned = String(o.qty_planned).replace(',', '.').replace(/[^0-9.]/g,''); recalcPercentages()"
                                           min="0.0001" step="0.0001" placeholder="Qty"
                                           :class="o.fromSo && Number(o.qty_planned) > o.qty_remaining_so ? 'border-red-400 bg-red-50 text-red-700' : 'border-gray-200 bg-white'"
                                           class="w-24 border rounded-lg px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    <select :name="`outputs[${idx}][output_type]`" x-model="o.output_type"
                                            @change="onOutputTypeChange(idx)"
                                            class="w-28 border border-gray-200 rounded-lg px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                        <option value="main">Utama</option>
                                        <option value="by_product">Sampingan</option>
                                    </select>
                                    {{-- Persentase: sampingan TANPA BOM bisa di-override manual (ukuran material beda → % beda).
                                         DENGAN BOM atau baris Utama → otomatis & terkunci. --}}
                                    <input type="text" inputmode="decimal" :name="`outputs[${idx}][percentage]`" x-model="o.percentage"
                                           @input="o.percentage = String(o.percentage).replace(',', '.').replace(/[^0-9.]/g,''); recalcPercentages()"
                                           :readonly="o.output_type === 'main' || (!!selectedBomId && !isManualByproduct(o))"
                                           step="0.0001" min="0" max="100"
                                           :title="(o.output_type === 'main' || (selectedBomId && !isManualByproduct(o))) ? 'Persentase otomatis (terkunci)' : 'Isi persentase manual'"
                                           :class="(o.output_type === 'main' || (selectedBomId && !isManualByproduct(o)))
                                               ? 'bg-gray-100 text-gray-600 cursor-not-allowed'
                                               : 'bg-white text-gray-800'"
                                           class="w-24 border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400">
                                    <button type="button" @click="removeOutput(idx)"
                                            class="p-2 text-red-400 hover:text-red-600 rounded-lg transition mt-0.5">✕</button>
                                </div>
                                <template x-if="o.fromSo">
                                    <div class="mt-1.5 ml-1 text-[10px] flex items-center gap-2">
                                        <span class="inline-block bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-bold">Dari SO</span>
                                        <span class="text-gray-500">
                                            Dipesan: <span class="font-bold text-gray-700" x-text="o.qty_ordered"></span>
                                            · Sudah direncanakan: <span class="font-bold text-gray-700" x-text="o.qty_already"></span>
                                            · Sisa max: <span class="font-bold text-purple-700" x-text="o.qty_remaining_so"></span>
                                        </span>
                                        <span x-show="Number(o.qty_planned) > o.qty_remaining_so"
                                              class="text-red-600 font-bold">⚠ melebihi sisa SO</span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Indikator total persentase: wajib 100% --}}
                    <div x-show="outputs.length > 0" class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                        <span class="text-gray-500">Total persentase output <span class="text-gray-400">(harus 100%)</span></span>
                        <span :class="outputsPercentageOk() ? 'text-green-600 font-bold' : 'text-red-600 font-bold'">
                            <span x-text="percentageTotal()"></span>%<span x-text="percentageHint()"></span>
                        </span>
                    </div>
                </div>

                {{-- Biaya Produksi --}}
                <div id="costAnchor" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="font-bold text-gray-700">Biaya Produksi</h3>
                            <p class="text-[10px] text-gray-400 mt-0.5">Biaya kas langsung (komponen kecil, jasa, dll). Jurnal Dr.WIP / Cr.Kas otomatis.</p>
                        </div>
                        <button type="button" @click="addCost()"
                                class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah Biaya</button>
                    </div>

                    <div x-show="costs.length === 0" class="text-xs text-gray-400 text-center py-3">
                        Opsional. Tambah jika ada pengeluaran kas langsung untuk produksi ini.
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
                                    <option value="">— Pilih Kas/Bank —</option>
                                    @foreach($cashAccounts as $acc)
                                        <option value="{{ $acc->id }}">{{ $acc->code }} · {{ $acc->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" @click="costs.splice(idx, 1)"
                                        class="p-2 text-red-400 hover:text-red-600 rounded-lg transition">✕</button>
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

            {{-- Sidebar --}}
            <div class="col-span-4 space-y-4">

                {{-- Langkah Produksi --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-gray-700 text-sm flex items-center gap-2">
                            <span class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center text-[10px] font-black">S</span>
                            Langkah Produksi
                        </h3>
                        <button type="button" @click="addStep()"
                                class="text-xs text-blue-600 font-bold hover:text-blue-700">+ Tambah</button>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(s, idx) in steps" :key="idx">
                            <div class="flex gap-2 items-center p-2.5 bg-gray-50 rounded-xl">
                                <span class="w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-[10px] font-black flex items-center justify-center flex-shrink-0"
                                      x-text="idx + 1"></span>
                                <input type="hidden" :name="`steps[${idx}][name]`" :value="s.name || s.department_name || ('Langkah ' + (idx+1))">
                                <select :name="`steps[${idx}][department_id]`" x-model="s.department_id"
                                        @change="s.department_name = $el.options[$el.selectedIndex].text"
                                        class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                    <option value="">— Departemen —</option>
                                    @foreach($departments as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" @click="steps.splice(idx, 1)"
                                        class="text-red-400 hover:text-red-600 transition flex-shrink-0 text-sm">✕</button>
                            </div>
                        </template>
                        <div x-show="steps.length === 0" class="text-xs text-gray-400 text-center py-3">
                            Otomatis diisi dari BOM, atau tambah manual.
                        </div>
                    </div>
                </div>

                {{-- Instruksi Kerja + Gambar (gabungan) --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 text-sm flex items-center gap-2 mb-3">
                        <span class="w-5 h-5 bg-amber-100 text-amber-600 rounded flex items-center justify-center text-[10px] font-black">I</span>
                        Instruksi Kerja
                    </h3>

                    {{-- Deskripsi --}}
                    <textarea name="description" x-model="description" rows="4"
                              placeholder="Spesifikasi produk, instruksi kerja, atau catatan penting untuk tim produksi..."
                              class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-400 resize-y mb-1"></textarea>
                    <p x-show="description && selectedBomId" class="text-[10px] text-amber-600 mb-3">Diisi otomatis dari BOM. Bisa diedit.</p>

                    {{-- Gambar Kerja --}}
                    <div class="border-t border-gray-100 pt-3">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">
                                Gambar Kerja <span class="text-gray-300 font-normal" x-text="`(${images.length}/5)`"></span>
                            </div>
                            <template x-if="images.length < 5">
                                <label class="cursor-pointer text-[10px] text-blue-600 font-bold hover:text-blue-700">
                                    <input type="file" id="imageFileInput" name="images[]" accept="image/*" multiple class="sr-only"
                                           @change="addFilesFromInput($event)">
                                    + Tambah File
                                </label>
                            </template>
                        </div>

                        {{-- Area paste (tidak buka explorer saat diklik) --}}
                        <div x-show="images.length === 0"
                             class="border-2 border-dashed border-gray-200 rounded-xl p-5 text-center text-gray-400 select-none">
                            <div class="text-xl mb-1">📋</div>
                            <div class="text-xs font-medium">Ctrl+V untuk paste screenshot</div>
                            <div class="text-[10px] mt-0.5">atau klik "+ Tambah File" di atas</div>
                        </div>

                        {{-- Grid preview gambar --}}
                        <div x-show="images.length > 0" class="grid grid-cols-2 gap-2">
                            <template x-for="(img, idx) in images" :key="idx">
                                <div class="relative group rounded-xl overflow-hidden border border-gray-200">
                                    <img :src="img.preview" class="w-full object-cover" style="height:90px;">
                                    <button type="button" @click="removeImage(idx)"
                                            class="absolute top-1 right-1 w-5 h-5 bg-red-500 text-white rounded-full text-[10px] font-black flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">✕</button>
                                </div>
                            </template>
                        </div>

                        {{-- Hidden inputs untuk submit --}}
                        <div id="imageHiddenInputs"></div>

                        <p x-show="images.length > 0" class="text-[10px] text-gray-400 mt-2 text-center">
                            Hover gambar untuk hapus · Ctrl+V untuk tambah paste
                        </p>
                    </div>
                </div>

                {{-- Skor Antrean --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-600 text-sm mb-3">Skor Antrean</h3>
                    <div class="flex rounded-lg border overflow-hidden text-xs font-bold mb-2"
                         :class="type === 'repair' ? 'border-orange-200' : 'border-gray-200'">
                        <label class="flex-1 flex items-center justify-center px-3 py-2 transition"
                               :class="type === 'repair'
                                   ? 'bg-gray-100 text-gray-300 cursor-not-allowed'
                                   : scoreType === 'auto' ? 'bg-blue-600 text-white cursor-pointer' : 'bg-white text-gray-500 hover:bg-gray-50 cursor-pointer'">
                            <input type="radio" name="score_type" value="auto" x-model="scoreType"
                                   :disabled="type === 'repair'" class="sr-only">
                            Otomatis
                        </label>
                        <label class="flex-1 flex items-center justify-center px-3 py-2 border-l cursor-pointer transition"
                               :class="type === 'repair' ? 'border-orange-200' : 'border-gray-200'"
                               :class="scoreType === 'priority' ? 'bg-orange-500 text-white' : 'bg-white text-gray-500 hover:bg-gray-50'">
                            <input type="radio" name="score_type" value="priority" x-model="scoreType" class="sr-only">
                            Prioritas
                        </label>
                    </div>
                    <div x-show="scoreType === 'priority'" x-transition>
                        <select name="priority_level" x-model="priorityLevel"
                                class="w-full border border-orange-200 rounded-lg px-3 py-2 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-orange-400 bg-orange-50 text-orange-700">
                            <option value="low">Rendah (0)</option>
                            <option value="medium">Sedang (100)</option>
                            <option value="high">Tinggi (200)</option>
                            <option value="very_high">Sangat Tinggi (300)</option>
                            <option value="urgent">Mendesak (400)</option>
                        </select>
                    </div>
                    <p x-show="scoreType === 'auto'" class="text-[10px] text-gray-400 mt-1">Dihitung otomatis dari BOM + kondisi stok.</p>
                    <p x-show="type === 'repair'" class="text-[10px] text-orange-400 mt-1">Order perbaikan menggunakan prioritas manual karena tidak memiliki BOM.</p>
                </div>

                {{-- Simpan Order --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <div x-show="submitError" x-transition x-text="submitError"
                         class="mb-3 bg-red-50 border border-red-200 text-red-700 text-xs rounded-lg px-3 py-2"></div>
                    <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl text-sm font-bold transition">
                        Buat Order Produksi
                    </button>
                    <p class="text-[10px] text-gray-400 text-center mt-2">Order tersimpan sebagai Draft. Konfirmasi dari halaman detail untuk mulai produksi.</p>
                </div>

            </div>

        </div>
    </form>

    {{-- ═══════════ MODAL: Kalkulator Produk Custom ═══════════ --}}
    <style>[x-cloak]{display:none!important}</style>
    <div x-show="calc.open" x-cloak
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
         @keydown.escape.window="calc.open = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl my-6" @click.outside="calc.open = false">
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 sticky top-0 bg-white rounded-t-2xl z-10">
                <h3 class="font-bold text-gray-800 flex items-center gap-2">🧮 Kalkulator Produk Custom</h3>
                <button type="button" @click="calc.open = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>

            <div class="px-6 py-5 space-y-6">

                {{-- ── A. Bahan Baku & Potongan ── --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-bold text-gray-700 text-sm">1. Bahan Baku & Potongan</h4>
                        <button type="button" @click="calcAddRow()"
                                class="text-xs text-emerald-600 font-bold hover:text-emerald-700">+ Tambah Baris</button>
                    </div>
                    <div class="space-y-3">
                        <template x-for="(row, ri) in calc.rows" :key="ri">
                            <div class="border border-gray-200 rounded-xl p-3 bg-gray-50/60">
                                {{-- Pilih bahan baku + jumlah + total --}}
                                <div class="flex gap-2 items-start">
                                    <div class="flex-1 relative" @click.outside="row.showDrop = false">
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Bahan Baku</label>
                                        <input type="text" x-model="row.rmQuery"
                                               @input="row.showDrop = true" @focus="row.showDrop = true"
                                               placeholder="Cari bahan baku terdaftar..."
                                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                        <div x-show="row.showDrop && calcFilteredRaw(row).length > 0"
                                             class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                            <template x-for="rm in calcFilteredRaw(row)" :key="rm.id">
                                                <div @click="calcPickRaw(row, rm)"
                                                     class="px-3 py-2 text-sm hover:bg-emerald-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                    <span class="text-gray-700" x-text="rm.label"></span>
                                                    <span class="text-[10px] text-gray-400 ml-1" x-text="calcFmt(rm.panjang)+'×'+calcFmt(rm.lebar)+' cm'"></span>
                                                </div>
                                            </template>
                                        </div>
                                        <p x-show="row.rmId" class="text-[10px] text-gray-500 mt-1">
                                            Lembar: <b x-text="calcFmt(row.panjang)+' × '+calcFmt(row.lebar)+' cm'"></b>
                                            · Harga/lembar: Rp <span x-text="Number(row.lastCost).toLocaleString('id-ID')"></span>
                                        </p>
                                    </div>
                                    <div class="w-20">
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Jumlah</label>
                                        <input type="number" x-model="row.jumlah" min="0" step="1"
                                               class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                    </div>
                                    <div class="w-28">
                                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Total B. Baku</label>
                                        <div class="w-full border border-gray-100 rounded-lg px-2 py-2 text-sm text-center font-bold bg-emerald-50 text-emerald-700"
                                             x-text="calcRowTotal(row)"></div>
                                    </div>
                                    <button type="button" @click="calcRemoveRow(ri)"
                                            class="p-2 text-red-400 hover:text-red-600 mt-5">✕</button>
                                </div>

                                {{-- Potongan --}}
                                <div class="mt-3 pl-1">
                                    <div class="grid grid-cols-[1fr_1fr_1.4fr_auto] gap-2 text-[10px] font-bold text-gray-400 uppercase mb-1 pr-7">
                                        <span>P (cm)</span><span>L (cm)</span><span>Keterangan</span><span></span>
                                    </div>
                                    <template x-for="(c, ci) in row.cuts" :key="ci">
                                        <div class="grid grid-cols-[1fr_1fr_1.4fr_auto] gap-2 items-start mb-1.5">
                                            <input type="number" x-model="c.P" min="0" step="0.01" placeholder="P"
                                                   class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                            <input type="number" x-model="c.L" min="0" step="0.01" placeholder="L"
                                                   class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                            <div class="flex gap-1">
                                                <select x-model="c.ket"
                                                        class="flex-1 border border-gray-200 rounded-lg px-1 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                                    <template x-for="k in calc.ketOptions" :key="k">
                                                        <option :value="k" x-text="k"></option>
                                                    </template>
                                                </select>
                                                <input type="text" x-show="c.ket === 'lainnya'" x-model="c.ketLain" placeholder="ket..."
                                                       class="w-20 border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                            </div>
                                            <button type="button" @click="calcRemoveCut(row, ci)"
                                                    class="p-1.5 text-red-300 hover:text-red-600">✕</button>
                                        </div>
                                    </template>
                                    <div class="flex items-center justify-between mt-1">
                                        <button type="button" @click="calcAddCut(row)"
                                                class="text-[11px] text-emerald-600 font-bold hover:text-emerald-700">+ Tambah Potongan</button>
                                        <span x-show="!calcCutSumOk(row)" class="text-[10px] text-red-500 font-semibold">
                                            ⚠ Total panjang potongan melebihi panjang lembar (<span x-text="calcFmt(row.panjang)"></span> cm)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div x-show="calc.rows.length === 0" class="text-xs text-gray-400 text-center py-4 border border-dashed border-gray-200 rounded-xl">
                            Klik "+ Tambah Baris" untuk mulai menghitung kebutuhan bahan baku.
                        </div>
                    </div>
                </div>

                {{-- ── B. Produk Jadi ── --}}
                <div>
                    <h4 class="font-bold text-gray-700 text-sm mb-2">2. Produk Jadi</h4>
                    {{-- Produk utama --}}
                    <div class="flex gap-2 items-end mb-3">
                        <div class="flex-1 relative" @click.outside="calc.main.showDrop = false">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">
                                Produk Utama
                                <span x-show="calc.mainLinked" class="text-emerald-600 font-normal normal-case">· otomatis dari output</span>
                            </label>
                            <input type="text" x-model="calc.main.query"
                                   @input.debounce.300ms="!calc.mainLinked && calcMainSearch()" @focus="!calc.mainLinked && (calc.main.showDrop = true)"
                                   :readonly="calc.mainLinked"
                                   placeholder="Cari produk utama..."
                                   class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                                   :class="calc.mainLinked ? 'bg-emerald-50 border-emerald-200 text-emerald-800 cursor-not-allowed' : 'bg-white border-gray-200'">
                            <div x-show="!calc.mainLinked && calc.main.showDrop && calc.main.results.length > 0"
                                 class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-40 overflow-y-auto">
                                <template x-for="p in calc.main.results" :key="p.id">
                                    <div @click="calcMainSelect(p)"
                                         class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                        <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                        <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div class="w-20">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Jumlah</label>
                            <input type="number" x-model="calc.main.qty" min="0" step="1"
                                   :readonly="calc.mainLinked"
                                   class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-blue-400"
                                   :class="calc.mainLinked ? 'bg-emerald-50 border-emerald-200 text-emerald-800 cursor-not-allowed' : 'bg-white'">
                        </div>
                        <div class="w-24">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Persentase</label>
                            <div class="w-full border border-gray-100 rounded-lg px-2 py-2 text-sm text-center font-bold bg-gray-100 text-gray-600"
                                 x-text="calcMainPct()+'%'"></div>
                        </div>
                    </div>
                    {{-- Produk sampingan --}}
                    <div class="flex gap-2 items-end mb-2">
                        <div class="flex-1">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">Tambah Sampingan</label>
                            <select x-model="calc.bpPick"
                                    class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                <option value="">— Pilih produk sampingan —</option>
                                <template x-for="bp in byproducts" :key="bp.id">
                                    <option :value="bp.id" x-text="bp.label" x-show="!calc.bps.some(b => String(b.id) === String(bp.id))"></option>
                                </template>
                            </select>
                        </div>
                        <button type="button" @click="calcAddByproduct()"
                                class="bg-emerald-100 text-emerald-700 hover:bg-emerald-200 text-xs font-bold px-4 py-2.5 rounded-lg transition">+ Tambah</button>
                    </div>
                    <div class="space-y-1.5">
                        <template x-for="(bp, bi) in calc.bps" :key="bp.id">
                            <div class="flex gap-2 items-center bg-gray-50 border border-gray-100 rounded-lg px-3 py-2">
                                <span class="flex-1 text-sm text-gray-700" x-text="bp.label"></span>
                                <span class="text-[10px] text-gray-400" x-text="calcFmt(bp.panjang)+'×'+calcFmt(bp.lebar)+' cm'"></span>
                                <div class="w-16">
                                    <input type="number" x-model="bp.qty" min="0" step="1" placeholder="Qty"
                                           class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-center focus:outline-none focus:ring-2 focus:ring-emerald-400 bg-white">
                                </div>
                                <span class="w-16 text-center text-sm font-bold"
                                      :class="calcBpIncomplete(bp) ? 'text-red-500' : 'text-emerald-700'"
                                      x-text="calcBpIncomplete(bp) ? '—' : (calcByproductPct(bp)+'%')"></span>
                                <button type="button" @click="calcRemoveByproduct(bi)" class="text-red-400 hover:text-red-600">✕</button>
                            </div>
                        </template>
                        <p x-show="calc.bps.some(bp => calcBpIncomplete(bp))" class="text-[10px] text-red-500">
                            ⚠ Produk sampingan yang ditandai — lengkapi bahan baku & PxL-nya di Pengaturan Produksi.
                        </p>
                    </div>
                </div>

                {{-- ── C. Deskripsi (preview) ── --}}
                <div>
                    <h4 class="font-bold text-gray-700 text-sm mb-2">3. Deskripsi (otomatis)</h4>
                    <pre class="text-xs text-gray-600 bg-gray-50 border border-gray-100 rounded-lg px-3 py-2 whitespace-pre-wrap font-sans min-h-[2.5rem]"
                         x-text="calcBuildDescription() || '— belum ada potongan —'"></pre>
                </div>

                <div x-show="calc.error" x-transition x-text="calc.error"
                     class="bg-red-50 border border-red-200 text-red-700 text-xs rounded-lg px-3 py-2"></div>
            </div>

            {{-- Footer --}}
            <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-100 sticky bottom-0 bg-white rounded-b-2xl">
                <button type="button" @click="calc.open = false"
                        class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2.5 rounded-xl text-sm font-semibold transition">Tutup</button>
                <button type="button" @click="calcApply()"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition">
                    Terapkan ke Form
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
// Data input lama (saat submit gagal validasi) untuk mempertahankan isi form.
window.__poOld = {
    has_old:        @json(!empty(old('outputs')) || !empty(old('materials')) || !empty(old('type'))),
    type:           @json(old('type')),
    score_type:     @json(old('score_type')),
    priority_level: @json(old('priority_level')),
    planned_cycles: @json(old('planned_cycles')),
    description:    @json(old('description')),
    notes:          @json(old('notes')),
    sales_order_id: @json(old('sales_order_id')),
    so_label:       @json(old('so_label')),
    so_customer:    @json(old('so_customer')),
    materials:      @json(old('materials', [])),
    outputs:        @json(old('outputs', [])),
    steps:          @json(old('steps', [])),
    costs:          @json(old('costs', [])),
};
@php
$bomOptions = $boms->map(fn ($b) => [
    'id'             => $b->id,
    'bom_number'     => $b->bom_number,
    'name'           => $b->name,
    'typical_cycles' => (int) ($b->typical_cycles ?? 1),
])->values();
@endphp
window.__poBoms = @json($bomOptions);
window.__poByproducts = @json($byproductOptions ?? []);
window.__poRawMaterials = @json($rawMaterialOptions ?? []);
</script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function orderForm() {
    return {
        type: 'ready_stock',
        scoreType: 'auto',
        priorityLevel: 'medium',
        repairSource: '',
        repairSourceId: null,
        repairSourceRef: '',
        repairSourceQuery: '',
        repairSourceResults: [],
        repairSourceDrop: false,
        repairItems: [],
        salesOrderId: null,
        salesOrderLabel: '',
        salesOrderCustomer: '',
        salesOrderQuery: '',
        salesOrderResults: [],
        salesOrderDrop: false,
        selectedBomId: '',
        bomOpen: false,
        bomLabel: '',
        bomName: '',
        bomQuery: '',
        bomResults: [],
        bomDrop: false,
        bomList: window.__poBoms || [],
        byproducts: window.__poByproducts || [],
        cycles: 1,
        loading: false,
        description: '',
        submitError: '',
        materials: [],
        outputs: [],
        steps: [],
        costs: [],
        images: [],
        _fileList: [],

        // ── Kalkulator Produk Custom ──
        calc: {
            open: false,
            rawMaterials: window.__poRawMaterials || [],
            ketOptions: ['tidak dilepas', 'lepas 1 sisi', 'lepas 2 sisi', 'non stock', 'lainnya'],
            rows: [],
            main: { product_id: '', query: '', qty: 1, results: [], showDrop: false },
            mainLinked: false,   // produk utama diambil dari output yang sudah ada (mis. dari SO)
            bps: [],
            bpPick: '',
            error: '',
        },

        addMaterial() { this.materials.push({ product_id: null, productQuery: '', qty_required: 1, unit: '', unitOptions: [], results: [], showDrop: false }); },
        addOutput() {
            // Hanya boleh 1 Utama. Output pertama = Utama; berikutnya otomatis Sampingan.
            const hasMain = this.outputs.some(o => o.output_type === 'main');
            this.outputs.push({
                product_id: '', productQuery: '', qty_planned: 1,
                output_type: hasMain ? 'by_product' : 'main',
                percentage: hasMain ? 0 : 100, unit_percentage: null,
                results: [], showDrop: false, fromSo: false
            });
            this.recalcPercentages();
        },
        addStep()     { this.steps.push({ name: '', department_id: '', department_name: '' }); },
        addCost()     { this.costs.push({ description: '', amount: '', cash_account_id: '' }); },

        // ── Persentase output: utama = sisa (100 − Σ sampingan). ──
        //  • DENGAN BOM: sampingan = unit% × (qty/siklus), otomatis & terkunci.
        //  • TANPA BOM: ukuran material bisa beda → sampingan diisi/di-override manual user;
        //    di sini hanya hitung ulang utama, jangan timpa % sampingan yang diketik.
        // Output sampingan dianggap "manual" (persentase wajib diisi sendiri) bila
        // produk sampingan tsb tidak punya % default di Pengaturan Produksi (unit_percentage kosong).
        isManualByproduct(o) {
            return o.output_type === 'by_product'
                && (o.unit_percentage === null || o.unit_percentage === undefined || o.unit_percentage === '');
        },
        recalcPercentages() {
            const cyc = parseFloat(this.cycles) || 1;
            const usesBom = !!this.selectedBomId;
            let sumBp = 0;
            this.outputs.forEach(o => {
                if (o.output_type === 'by_product') {
                    // Auto-hitung hanya bila pakai BOM DAN ada % master; selain itu hormati input manual.
                    if (usesBom && !this.isManualByproduct(o)) {
                        const up  = parseFloat(o.unit_percentage) || 0;
                        const qty = parseFloat(o.qty_planned) || 0;
                        o.percentage = Math.round(up * (qty / cyc) * 10000) / 10000;
                    }
                    sumBp += parseFloat(o.percentage) || 0;
                }
            });
            const mainPct = Math.round((100 - sumBp) * 10000) / 10000;
            this.outputs.forEach(o => {
                if (o.output_type === 'main') { o.unit_percentage = null; o.percentage = mainPct; }
            });
        },

        // Skala qty material & output mengikuti Jumlah Siklus — SINKRON (tanpa fetch async).
        // Hanya baris yang berasal dari BOM (punya _perCycle) yang di-skala; baris tambahan
        // manual dibiarkan. Dipanggil dari $watch('cycles') agar qty selalu sinkron dengan
        // nilai siklus yang akan ter-submit (mencegah desync planned_cycles vs qty).
        applyCycles() {
            const cyc = parseFloat(this.cycles) || 0;
            if (this.selectedBomId && cyc > 0) {
                const round4 = (n) => Math.round(n * 10000) / 10000;
                this.materials.forEach(m => {
                    if (m._perCycle != null) m.qty_required = round4(m._perCycle * cyc);
                });
                this.outputs.forEach(o => {
                    if (o._perCycle != null) o.qty_planned = round4(o._perCycle * cyc);
                });
            }
            this.recalcPercentages();
        },
        selectByproduct(idx) {
            const o  = this.outputs[idx];
            const bp = this.byproducts.find(b => String(b.id) === String(o.product_id));
            o.unit_percentage = bp ? bp.unit_percentage : null;
            o.productQuery = bp ? bp.label : '';
            if (this.isManualByproduct(o)) {
                // Tidak ada % default → kosongkan agar user wajib mengisi manual.
                o.percentage = '';
            } else if (!this.selectedBomId) {
                // Tanpa BOM tapi punya % master: prefill saran (unit% × qty/siklus) — boleh diubah.
                const cyc = parseFloat(this.cycles) || 1;
                const qty = parseFloat(o.qty_planned) || 0;
                o.percentage = Math.round((parseFloat(o.unit_percentage) || 0) * (qty / cyc) * 100) / 100;
            }
            this.recalcPercentages();
        },
        // Live-search produk sampingan: filter daftar terdaftar (Pengaturan) secara lokal.
        filteredByproducts(o) {
            const q = (o.productQuery || '').toLowerCase().trim();
            if (!q) return this.byproducts;
            return this.byproducts.filter(bp => (bp.label || '').toLowerCase().includes(q));
        },
        pickByproduct(idx, bp) {
            const o = this.outputs[idx];
            o.product_id = String(bp.id);
            o.showDrop = false;
            this.selectByproduct(idx);
        },

        // ───────── Kalkulator Produk Custom ─────────
        openCalc() {
            // Produk utama langsung diisi dari output utama yang sudah ada (mis. dari SO),
            // supaya tidak perlu cari/isi ulang. Field jadi terkunci (mengikuti output).
            const mainOut = this.outputs.find(o => o.output_type === 'main' && o.product_id);
            if (mainOut) {
                this.calc.main.product_id = mainOut.product_id;
                this.calc.main.query      = mainOut.productQuery;
                this.calc.main.qty        = mainOut.qty_planned;
                this.calc.mainLinked      = true;
            } else {
                this.calc.mainLinked = false;
            }
            this.calc.open = true;
        },
        calcAddRow() {
            this.calc.rows.push({
                rmId: '', rmQuery: '', sku: '', panjang: 0, lebar: 0, lastCost: 0, baseUnit: '',
                cuts: [{ P: '', L: '', ket: 'tidak dilepas', ketLain: '' }],
                jumlah: 1, showDrop: false,
            });
        },
        calcRemoveRow(i) { this.calc.rows.splice(i, 1); },
        calcFilteredRaw(row) {
            const q = (row.rmQuery || '').toLowerCase().trim();
            if (!q) return this.calc.rawMaterials;
            return this.calc.rawMaterials.filter(rm => (rm.label || '').toLowerCase().includes(q));
        },
        calcPickRaw(row, rm) {
            row.rmId = rm.id; row.rmQuery = rm.label; row.sku = rm.sku;
            row.panjang = rm.panjang; row.lebar = rm.lebar; row.lastCost = rm.last_cost; row.baseUnit = rm.base_unit;
            row.showDrop = false;
            // Lebar tiap potongan default = lebar lembar (boleh diubah).
            row.cuts.forEach(c => { if (c.L === '' || c.L === null) c.L = rm.lebar; });
        },
        calcAddCut(row) { row.cuts.push({ P: '', L: row.lebar || '', ket: 'tidak dilepas', ketLain: '' }); },
        calcRemoveCut(row, ci) { row.cuts.splice(ci, 1); },
        calcRowArea(row) {
            return (row.cuts || []).reduce((s, c) => s + (parseFloat(c.P) || 0) * (parseFloat(c.L) || 0), 0);
        },
        calcRowTotal(row) {
            const sheet = (parseFloat(row.panjang) || 0) * (parseFloat(row.lebar) || 0);
            if (sheet <= 0) return 0;
            return Math.round(this.calcRowArea(row) / sheet * (parseFloat(row.jumlah) || 0) * 100) / 100;
        },
        calcCutSumOk(row) {
            const sumP = (row.cuts || []).reduce((s, c) => s + (parseFloat(c.P) || 0), 0);
            const P = parseFloat(row.panjang) || 0;
            if (P <= 0 || sumP <= 0) return true; // belum lengkap → jangan ganggu
            return sumP <= P + 0.01; // boleh kurang dari panjang lembar, tidak boleh lebih
        },
        calcTotalMaterialCost() {
            return this.calc.rows.reduce((s, row) => s + this.calcRowTotal(row) * (parseFloat(row.lastCost) || 0), 0);
        },
        calcByproductPct(bp) {
            const tmc = this.calcTotalMaterialCost();
            const area = (parseFloat(bp.panjang) || 0) * (parseFloat(bp.lebar) || 0);
            if (tmc <= 0 || bp.rm_area <= 0 || area <= 0) return 0;
            const cost = area * (parseFloat(bp.qty) || 0) * ((parseFloat(bp.rm_last_cost) || 0) / bp.rm_area);
            return Math.round(cost / tmc * 100 * 100) / 100;
        },
        calcMainPct() {
            const sum = this.calc.bps.reduce((s, bp) => s + this.calcByproductPct(bp), 0);
            return Math.round((100 - sum) * 100) / 100;
        },
        calcBpIncomplete(bp) {
            return !(bp.rm_area > 0) || !((parseFloat(bp.panjang) || 0) > 0 && (parseFloat(bp.lebar) || 0) > 0);
        },
        calcMainSearch() {
            const q = (this.calc.main.query || '').trim();
            if (q.length < 2) { this.calc.main.results = []; return; }
            fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`)
                .then(res => res.json())
                .then(data => { this.calc.main.results = data; this.calc.main.showDrop = true; });
        },
        calcMainSelect(p) {
            this.calc.main.product_id = p.id;
            this.calc.main.query = (p.sku ? p.sku + ' - ' : '') + p.name;
            this.calc.main.results = [];
            this.calc.main.showDrop = false;
        },
        calcAddByproduct() {
            const id = this.calc.bpPick;
            if (!id) return;
            if (this.calc.bps.some(b => String(b.id) === String(id))) { this.calc.bpPick = ''; return; }
            const bp = this.byproducts.find(b => String(b.id) === String(id));
            if (!bp) { this.calc.bpPick = ''; return; }
            this.calc.bps.push({
                id: bp.id, label: bp.label, qty: 1,
                panjang: bp.panjang, lebar: bp.lebar, rm_area: bp.rm_area, rm_last_cost: bp.rm_last_cost,
            });
            this.calc.bpPick = '';
        },
        calcRemoveByproduct(i) { this.calc.bps.splice(i, 1); },
        calcKetLabel(c) { return c.ket === 'lainnya' ? (c.ketLain || 'lainnya') : c.ket; },
        calcFmt(v) {
            const n = parseFloat(v) || 0;
            return (Math.round(n * 100) / 100).toString();
        },
        calcBuildDescription() {
            return this.calc.rows
                .filter(row => row.rmId && (row.cuts || []).length)
                .map(row => {
                    const parts = row.cuts
                        .filter(c => (parseFloat(c.P) || 0) > 0)
                        .map(c => `${this.calcFmt(c.P)}x${this.calcFmt(c.L)} - ${this.calcKetLabel(c)}`)
                        .join(', ');
                    return `${row.sku || row.rmQuery} = ${parts} @ ${this.calcFmt(row.jumlah)} lembar`;
                })
                .join('\n');
        },
        calcApply() {
            this.calc.error = '';
            const rows = this.calc.rows.filter(r => r.rmId);
            if (!rows.length) { this.calc.error = 'Pilih minimal satu bahan baku beserta potongannya.'; return; }
            if (!this.calc.main.product_id) { this.calc.error = 'Pilih produk utama.'; return; }
            if (rows.some(r => !this.calcCutSumOk(r))) {
                this.calc.error = 'Total panjang potongan tidak boleh melebihi panjang lembar bahan baku.'; return;
            }
            if (this.calc.bps.some(bp => this.calcBpIncomplete(bp))) {
                this.calc.error = 'Lengkapi PxL & bahan baku produk sampingan di Pengaturan agar persentase bisa dihitung.'; return;
            }

            // 1) Material (append)
            rows.forEach(row => {
                this.materials.push({
                    product_id: row.rmId, productQuery: row.rmQuery,
                    qty_required: this.calcRowTotal(row), unit: row.baseUnit,
                    unitOptions: row.baseUnit ? [row.baseUnit] : [], results: [], showDrop: false,
                });
            });

            // 2) Output (append): utama + sampingan.
            //    Bila sudah ada output utama (mis. dari SO), pakai itu — jangan tambah utama baru.
            const existingMain = this.outputs.find(o => o.output_type === 'main' && o.product_id);
            if (!existingMain) {
                this.outputs.push({
                    product_id: this.calc.main.product_id, productQuery: this.calc.main.query,
                    qty_planned: parseFloat(this.calc.main.qty) || 1,
                    output_type: 'main',
                    percentage: 0, unit_percentage: null, results: [], showDrop: false, fromSo: false,
                });
            }
            this.calc.bps.forEach(bp => {
                this.outputs.push({
                    product_id: String(bp.id), productQuery: bp.label,
                    qty_planned: parseFloat(bp.qty) || 1,
                    output_type: 'by_product', percentage: this.calcByproductPct(bp),
                    unit_percentage: null, results: [], showDrop: false, fromSo: false,
                });
            });
            // Utama menyerap sisa (100 − Σ sampingan); logika no-BOM mempertahankan % sampingan.
            this.recalcPercentages();

            // 3) Deskripsi (append)
            const desc = this.calcBuildDescription();
            if (desc) this.description = this.description ? (this.description.trimEnd() + '\n' + desc) : desc;

            this.calc.open = false;
        },

        // Hanya boleh 1 produk Utama; sisanya Sampingan. Persentase otomatis dari Pengaturan.
        onOutputTypeChange(idx) {
            const o = this.outputs[idx];
            if (o.output_type === 'main') {
                this.outputs.forEach((other, i) => {
                    if (i !== idx && other.output_type === 'main') other.output_type = 'by_product';
                });
                o.unit_percentage = null;
            }
            // Reset produk saat pindah jenis agar tidak salah daftar.
            o.product_id = ''; o.productQuery = ''; o.results = []; o.unit_percentage = null;
            this.recalcPercentages();
        },

        removeOutput(idx) {
            this.outputs.splice(idx, 1);
            if (this.outputs.length && !this.outputs.some(o => o.output_type === 'main')) {
                this.outputs[0].output_type = 'main';
            }
            this.recalcPercentages();
        },

        // ── Validasi total persentase output (harus 100%) ──
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
            // Jangan submit selagi data BOM sedang dimuat — qty material/output belum final.
            if (this.loading) {
                e.preventDefault();
                this.submitError = 'Data BOM masih dimuat. Tunggu sebentar lalu simpan lagi.';
                return;
            }
            // Order perbaikan tidak pakai outputs[] biasa.
            if (this.type === 'repair') return;

            const badOutput = this.outputs.length === 0
                || this.outputs.some(o => !o.product_id || !(parseFloat(o.qty_planned) > 0));
            if (badOutput) {
                e.preventDefault();
                this.submitError = 'Setiap baris Output harus punya produk dan qty. Lengkapi atau hapus barisnya.';
                document.querySelector('#outputAnchor')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (!this.outputsPercentageOk()) {
                e.preventDefault();
                this.submitError = `Total persentase output harus 100% (saat ini ${this.percentageTotal()}%).`;
                document.querySelector('#outputAnchor')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            // Tanpa BOM: % sampingan diisi manual — cegah totalnya melebihi 100% (utama jadi negatif).
            if (this.outputs.some(o => (parseFloat(o.percentage) || 0) < 0)) {
                e.preventDefault();
                this.submitError = 'Total persentase produk sampingan melebihi 100% sehingga produk utama negatif. Kurangi persentase sampingan.';
                document.querySelector('#outputAnchor')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            // Produk tidak boleh jadi bahan baku sekaligus output (produksi sirkular).
            const materialIds = this.materials.map(m => m.product_id).filter(Boolean);
            const overlap = this.outputs.find(o => o.product_id && materialIds.includes(o.product_id));
            if (overlap) {
                e.preventDefault();
                this.submitError = `Produk "${overlap.productQuery}" ada di Material sekaligus Output. Produk tidak boleh keduanya.`;
                document.querySelector('#outputAnchor')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            // Biaya: baris yang ada nominalnya wajib lengkap (keterangan + Kas/Bank).
            const badCost = this.costs.some(c =>
                (parseFloat(c.amount) > 0) && (!c.cash_account_id || !(c.description && String(c.description).trim())));
            if (badCost) {
                e.preventDefault();
                this.submitError = 'Lengkapi baris Biaya Produksi (keterangan + jumlah + Kas/Bank) atau hapus barisnya.';
                document.querySelector('#costAnchor')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
        },

        // ── Live search BOM (filter client-side dari window.__poBoms) ──
        searchBoms() {
            const q = this.bomQuery.trim().toLowerCase();
            let list = this.bomList;
            if (q) {
                list = list.filter(b => `${b.bom_number} ${b.name}`.toLowerCase().includes(q));
            }
            this.bomResults = list.slice(0, 50);
            this.bomDrop = true;
        },

        selectBom(b) {
            this.selectedBomId = b.id;
            this.bomLabel      = b.bom_number;
            this.bomName       = b.name;
            this.bomQuery      = '';
            this.bomResults    = [];
            this.bomDrop       = false;
            // Default Jumlah Siklus = jumlah siklus yang tersimpan di BOM.
            if (b.typical_cycles > 0) this.cycles = b.typical_cycles;
            this.loadBom();
        },

        clearBom() {
            this.selectedBomId = '';
            this.bomLabel      = '';
            this.bomName       = '';
            this.bomQuery      = '';
            this.bomResults    = [];
            this.bomDrop       = false;
        },

        clearRepairSource() {
            this.repairSourceId      = null;
            this.repairSourceRef     = '';
            this.repairSourceQuery   = '';
            this.repairSourceResults = [];
            this.repairSourceDrop    = false;
            this.repairItems         = [];
        },

        selectRepairSource(doc) {
            this.repairSourceId    = doc.id;
            this.repairSourceRef   = doc.number;
            this.repairSourceQuery = doc.label;
            this.repairSourceDrop  = false;
            this.repairItems       = doc.items;
        },

        async searchSalesOrders() {
            const q   = this.salesOrderQuery.trim();
            const res = await fetch(`/erp/production/ajax/sales-orders?q=${encodeURIComponent(q)}`);
            this.salesOrderResults = await res.json();
            this.salesOrderDrop    = true;
        },

        selectSalesOrder(so) {
            this.salesOrderId       = so.id;
            this.salesOrderLabel    = so.order_number;
            this.salesOrderCustomer = so.customer_name;
            this.salesOrderQuery    = '';
            this.salesOrderResults  = [];
            this.salesOrderDrop     = false;

            // Auto-populate outputs dari item preorder SO
            this.outputs = so.items.map((item, idx) => ({
                product_id:       item.product_id,
                productQuery:     `${item.sku} - ${item.name}`,
                qty_planned:      item.qty_remaining,
                output_type:      idx === 0 ? 'main' : 'by_product',
                percentage:       idx === 0 ? 100 : '',
                results:          [],
                showDrop:         false,
                fromSo:           true,
                qty_ordered:      item.qty_ordered,
                qty_already:      item.qty_planned,
                qty_remaining_so: item.qty_remaining,
            }));
        },

        clearSalesOrder() {
            this.salesOrderId       = null;
            this.salesOrderLabel    = '';
            this.salesOrderCustomer = '';
            this.salesOrderQuery    = '';
            this.salesOrderResults  = [];
            this.salesOrderDrop     = false;
            this.outputs            = [];
        },

        // Prefill type=repair + sumber perbaikan dari querystring.
        async prefillFromUrl() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('type') !== 'repair') return;

            this.type = 'repair';
            const st  = params.get('repair_source_type');
            const ref = params.get('repair_ref') || '';
            const sid = params.get('repair_source_id');
            if (!st || !sid) return;

            this.repairSource = st;          // memicu watch → clearRepairSource()
            await this.$nextTick();          // tunggu watch selesai sebelum mengisi

            try {
                const url = `/erp/production/ajax/repair-sources?source_type=${encodeURIComponent(st)}&search=${encodeURIComponent(ref)}`;
                const res = await fetch(url);
                const docs = await res.json();
                const doc = docs.find(d => String(d.id) === String(sid)) || docs[0];
                if (doc) this.selectRepairSource(doc);
            } catch (e) { /* abaikan; user bisa cari manual */ }
        },

        async searchRepairSource() {
            if (!this.repairSource) return;
            const q   = this.repairSourceQuery;
            const url = `/erp/production/ajax/repair-sources?source_type=${this.repairSource}&search=${encodeURIComponent(q)}`;
            const res = await fetch(url);
            this.repairSourceResults = await res.json();
            this.repairSourceDrop    = this.repairSourceResults.length > 0;
        },

        init() {
            // Pulihkan isi form jika submit sebelumnya gagal validasi (data dari old()).
            const old = window.__poOld || {};
            if (old.has_old) {
                if (old.type) this.type = old.type;
                if (old.score_type) this.scoreType = old.score_type;
                if (old.priority_level) this.priorityLevel = old.priority_level;
                if (old.planned_cycles) this.cycles = old.planned_cycles;
                if (old.description != null) this.description = old.description;
                if (old.sales_order_id) {
                    this.salesOrderId       = old.sales_order_id;
                    this.salesOrderLabel    = old.so_label || ('SO #' + old.sales_order_id);
                    this.salesOrderCustomer = old.so_customer || '';
                }
                if (Array.isArray(old.materials) && old.materials.length) {
                    this.materials = old.materials.map(m => {
                        let opts = [];
                        try { opts = JSON.parse(m.unit_options || '[]'); } catch (e) { opts = []; }
                        return {
                            product_id: m.product_id || null,
                            productQuery: m.label || '',
                            qty_required: m.qty_required ?? 1,
                            unit: m.unit ?? '',
                            unitOptions: Array.isArray(opts) ? opts : [],
                            results: [], showDrop: false,
                        };
                    });
                }
                if (Array.isArray(old.outputs) && old.outputs.length) {
                    this.outputs = old.outputs.map(o => ({
                        product_id: o.product_id || '',
                        productQuery: o.label || '',
                        qty_planned: o.qty_planned ?? 1,
                        output_type: o.output_type || 'by_product',
                        percentage: (o.percentage === null || o.percentage === undefined) ? '' : o.percentage,
                        unit_percentage: (o.unit_percentage === null || o.unit_percentage === undefined || o.unit_percentage === '') ? null : o.unit_percentage,
                        results: [], showDrop: false, fromSo: false,
                    }));
                    this.recalcPercentages();
                }
                if (Array.isArray(old.steps) && old.steps.length) {
                    this.steps = old.steps.map(s => ({ name: s.name || '', department_id: s.department_id || '', department_name: '' }));
                }
                if (Array.isArray(old.costs) && old.costs.length) {
                    this.costs = old.costs.map(c => ({ description: c.description || '', amount: c.amount || '', cash_account_id: c.cash_account_id || '' }));
                }
            }

            // Jumlah siklus berubah → skala qty material/output (sinkron) + hitung ulang % sampingan.
            this.$watch('cycles', () => this.applyCycles());
            this.$watch('repairSource', () => this.clearRepairSource());
            this.$watch('type', (val) => {
                if (val === 'repair') {
                    this.scoreType = 'priority';
                } else {
                    this.clearRepairSource();
                    this.scoreType = 'auto';
                }
                if (val !== 'custom') {
                    this.clearSalesOrder();
                }
            });

            // Prefill dari querystring (cth: tombol "+ OP Perbaikan" di index Retur).
            // Hanya jika tidak sedang memulihkan input lama yang gagal validasi.
            if (!old.has_old) this.prefillFromUrl();

            document.addEventListener('paste', (e) => {
                // Jangan intercept paste saat user sedang mengetik di textarea/input
                const tag = document.activeElement?.tagName;
                if (tag === 'TEXTAREA' || tag === 'INPUT') return;

                const items = e.clipboardData?.items;
                if (!items) return;
                for (const item of items) {
                    if (item.type.startsWith('image/')) {
                        this.addFile(item.getAsFile());
                        break;
                    }
                }
            });
        },

        addFilesFromInput(event) {
            const files = Array.from(event.target.files);
            files.forEach(f => this.addFile(f));
            event.target.value = ''; // reset agar bisa pilih file yang sama lagi
        },

        addFile(file) {
            if (!file || this.images.length >= 5) return;
            const reader = new FileReader();
            reader.onload = (e) => {
                this.images.push({ preview: e.target.result, file });
                this._syncFileInput();
            };
            reader.readAsDataURL(file);
        },

        removeImage(idx) {
            this.images.splice(idx, 1);
            this._syncFileInput();
        },

        _syncFileInput() {
            const input = document.getElementById('imageFileInput');
            if (!input) return;
            const dt = new DataTransfer();
            this.images.forEach(img => dt.items.add(img.file));
            input.files = dt.files;
        },

        async loadBom() {
            if (!this.selectedBomId || !this.cycles) return;
            this.loading = true;
            try {
                const res = await fetch(`/erp/production/ajax/bom-calculate?bom_id=${this.selectedBomId}&cycles=${this.cycles}`);
                const data = await res.json();

                // Simpan basis per-siklus (qty pada saat dimuat ÷ siklus saat itu) agar perubahan
                // "Jumlah Siklus" bisa men-skala qty SECARA SINKRON di klien — bukan lewat fetch async
                // yang bisa kalah cepat dengan submit (akar bug: cycles & qty desync).
                const loadedCyc = parseFloat(this.cycles) || 1;

                this.materials = data.materials.map(m => ({
                    product_id: m.product_id,
                    productQuery: `${m.product_sku} - ${m.product_name}`,
                    qty_required: m.qty_required,
                    unit: m.unit ?? '',
                    unitOptions: (m.units ?? (m.unit ? [m.unit] : [])),
                    results: [], showDrop: false,
                    _perCycle: (parseFloat(m.qty_required) || 0) / loadedCyc,
                }));

                this.outputs = data.outputs.map(o => ({
                    product_id: o.product_id,
                    productQuery: `${o.product_sku} - ${o.product_name}`,
                    qty_planned: o.qty_planned,
                    output_type: o.output_type,
                    percentage: o.percentage ?? (o.output_type === 'main' ? 100 : ''),
                    unit_percentage: o.unit_percentage ?? null,
                    results: [], showDrop: false,
                    _perCycle: (parseFloat(o.qty_planned) || 0) / loadedCyc,
                }));
                this.recalcPercentages();

                this.steps = (data.steps ?? []).map(s => ({
                    name: s.name,
                    department_id: s.department_id ?? '',
                    department_name: s.department_name ?? '',
                }));

                if (data.description) {
                    this.description = data.description;
                }
            } finally {
                this.loading = false;
            }
        },

        async searchProduct(idx, list) {
            const q = this[list][idx].productQuery;
            if (q.length < 2) { this[list][idx].results = []; return; }
            const res = await fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`);
            this[list][idx].results = await res.json();
            this[list][idx].showDrop = true;
        },

        selectProduct(idx, p, list) {
            this[list][idx].product_id   = p.id;
            this[list][idx].productQuery = `${p.sku} - ${p.name}`;
            this[list][idx].results      = [];
            this[list][idx].showDrop     = false;

            // Material: auto-isi satuan dasar + sediakan opsi satuan lain dari produk.
            if (list === 'materials') {
                this[list][idx].unit        = p.base_unit || '';
                this[list][idx].unitOptions = Array.isArray(p.units) ? p.units : (p.base_unit ? [p.base_unit] : []);
            }
        },

        // Opsi satuan untuk 1 baris material; selalu sertakan nilai m.unit yang sedang dipakai.
        unitOptionsFor(m) {
            const opts = Array.isArray(m.unitOptions) ? [...m.unitOptions] : [];
            if (m.unit && !opts.includes(m.unit)) opts.unshift(m.unit);
            return opts;
        },
    };
}
</script>
@endpush
