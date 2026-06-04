@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="warrantyForm({{ isset($warranty) ? $warranty->load('items.product','customer','invoice','salesOrder')->toJson() : 'null' }})" x-init="init()">

    <div class="flex justify-between items-center mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium mb-1">
                <a href="{{ route('sales.warranty.index') }}" class="hover:text-blue-600 transition">Garansi</a>
                <span>›</span>
                <span class="text-gray-600">{{ isset($warranty) ? 'Edit Garansi' : 'Buat Garansi Baru' }}</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">
                {{ isset($warranty) ? 'Edit Garansi ' . $warranty->warranty_number : 'Form Garansi Baru' }}
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Klaim garansi tidak mempengaruhi saldo customer.</p>
        </div>
        <a href="{{ route('sales.warranty.index') }}"
           class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 hover:bg-gray-50 bg-white px-4 py-2 rounded-xl text-sm font-semibold transition-all">
            ← Kembali
        </a>
    </div>


    <form id="warrantyForm" method="POST"
          action="{{ isset($warranty) ? route('sales.warranty.update', $warranty->id) : route('sales.warranty.store') }}">
        @csrf
        @if(isset($warranty)) @method('PUT') @endif
        <input type="hidden" name="form_status" :value="formStatus">

        <div class="grid grid-cols-12 gap-5">

            {{-- LEFT --}}
            <div class="col-span-8 space-y-5">

                {{-- Section 1: Header --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-2 mb-5">
                        <span class="w-7 h-7 bg-blue-600 text-white rounded-lg flex items-center justify-center text-xs font-black">1</span>
                        <h3 class="font-bold text-gray-700">Informasi Garansi</h3>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mb-4">
                        {{-- Customer --}}
                        <div class="relative" @click.outside="showCustomerDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Customer <span class="text-red-500">*</span></label>
                            <input type="text" x-model="customerQuery"
                                @input.debounce.300ms="searchCustomer()"
                                @focus="if(customerQuery.length >= 1) showCustomerDropdown = true"
                                placeholder="Cari customer..."
                                autocomplete="off"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <input type="hidden" name="customer_id" :value="customerId">

                            <div x-show="showCustomerDropdown && customerResults.length > 0"
                                 class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-52 overflow-y-auto">
                                <template x-for="c in customerResults" :key="c.id">
                                    <div @click="selectCustomer(c)"
                                         class="px-4 py-2.5 text-sm font-semibold hover:bg-blue-50 hover:text-blue-700 cursor-pointer border-b border-gray-100 last:border-0"
                                         x-text="c.name"></div>
                                </template>
                            </div>
                        </div>

                        {{-- Ref Type --}}
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Jenis Referensi</label>
                            <select x-model="refType" @change="onRefTypeChange()"
                                    class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                <option value="none">— Tanpa Referensi —</option>
                                <option value="invoice">🛍️ Dari Invoice</option>
                                <option value="so">📋 Dari Sales Order</option>
                            </select>
                        </div>

                        {{-- Document Selector --}}
                        <div class="relative" x-show="refType !== 'none' && documents.length > 0" x-transition @click.outside="showDocDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5"
                                   x-text="refType === 'invoice' ? 'Invoice *' : 'Sales Order *'"></label>
                            <input type="text"
                                x-model="docQuery"
                                @input="showDocDropdown = true"
                                @focus="showDocDropdown = true"
                                placeholder="Cari nomor dokumen..."
                                autocomplete="off"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                            <input type="hidden" name="invoice_id"     :value="refType === 'invoice' ? selectedDocId : ''">
                            <input type="hidden" name="sales_order_id" :value="refType === 'so'      ? selectedDocId : ''">

                            <div x-show="showDocDropdown" class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-52 overflow-y-auto">
                                <template x-for="doc in filteredDocs" :key="doc.id">
                                    <div @click="selectDoc(doc)"
                                         class="px-4 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                        <div class="text-sm font-bold text-gray-800" x-text="doc.number"></div>
                                        <div class="text-xs text-gray-500 flex justify-between mt-0.5">
                                            <span x-text="doc.date"></span>
                                            <span class="font-semibold text-gray-700" x-text="`Rp ${fmt(doc.grand_total)}`"></span>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="filteredDocs.length === 0" class="px-4 py-3 text-sm text-gray-500 text-center italic">Tidak ditemukan</div>
                            </div>
                        </div>

                        {{-- Loading / no doc states --}}
                        <div class="col-span-3" x-show="refType !== 'none' && customerId && documents.length === 0 && !loadingDocs" x-transition>
                            <div class="text-center py-3 bg-amber-50 rounded-xl border border-amber-100">
                                <span class="text-amber-600 font-semibold text-sm"
                                      x-text="refType === 'invoice' ? '⚠️ Tidak ada invoice untuk customer ini.' : '⚠️ Tidak ada Sales Order aktif untuk customer ini.'"></span>
                            </div>
                        </div>
                        <div class="col-span-3" x-show="loadingDocs" x-transition>
                            <div class="flex items-center gap-2 text-blue-500 text-sm py-2">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-text="refType === 'invoice' ? 'Memuat invoice...' : 'Memuat Sales Order...'"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Doc Badge --}}
                    <div x-show="selectedDoc" x-transition class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-4 flex items-start gap-4 mb-4">
                        <div class="bg-blue-600 text-white rounded-lg p-2 mt-0.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-black text-blue-800 text-sm" x-text="selectedDoc?.number"></span>
                                <span class="bg-green-100 text-green-700 text-[10px] font-black px-2 py-0.5 rounded-full uppercase" x-text="selectedDoc?.status"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-xs text-gray-500">
                                <div>Tanggal: <span class="text-gray-700 font-semibold" x-text="selectedDoc?.date"></span></div>
                                <div>Total: <span class="text-gray-700 font-semibold" x-text="`Rp ${fmt(selectedDoc?.grand_total)}`"></span></div>
                                <div>Item: <span class="text-gray-700 font-semibold" x-text="`${selectedDoc?.items?.length ?? 0} produk`"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        {{-- Tanggal --}}
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Tanggal <span class="text-red-500">*</span></label>
                            <input type="date" name="warranty_date"
                                   value="{{ isset($warranty) ? $warranty->warranty_date->format('Y-m-d') : date('Y-m-d') }}"
                                   class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        {{-- Gudang --}}
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Gudang <span class="text-red-500">*</span></label>
                            @php $whSelected = old('warehouse_id', $warranty->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId()); @endphp
                            <select name="warehouse_id"
                                    class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                @foreach($warehouses as $wh)
                                    <option value="{{ $wh->id }}" {{ $whSelected == $wh->id ? 'selected' : '' }}>
                                        {{ $wh->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Jenis Garansi (selalu Perbaikan) --}}
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Jenis Garansi</label>
                            <input type="hidden" name="type" value="repair">
                            <div class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm bg-gray-50 text-gray-600 font-semibold">
                                Perbaikan
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Deskripsi Masalah</label>
                        <textarea name="issue_description" rows="2"
                                  placeholder="Jelaskan masalah yang dilaporkan customer..."
                                  class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none">{{ isset($warranty) ? $warranty->issue_description : '' }}</textarea>
                    </div>
                </div>

                {{-- Section 2: Items --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <span class="w-7 h-7 bg-blue-600 text-white rounded-lg flex items-center justify-center text-xs font-black">2</span>
                            <h3 class="font-bold text-gray-700">Produk yang Bermasalah</h3>
                        </div>
                        <button type="button" @click="addManualItem()"
                                class="inline-flex items-center gap-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-bold transition">
                            + Tambah Manual
                        </button>
                    </div>

                    {{-- Table header --}}
                    <template x-if="items.length > 0">
                        <div class="overflow-hidden rounded-xl border border-gray-100 mb-2">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-gray-50 border-b border-gray-100">
                                        <th class="px-4 py-2.5 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Produk</th>
                                        <th class="px-3 py-2.5 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-20">Qty Asli</th>
                                        <th class="px-3 py-2.5 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-24">Qty Klaim</th>
                                        <th class="px-3 py-2.5 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Deskripsi Masalah</th>
                                        <th class="w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <template x-for="(item, idx) in items" :key="idx">
                                        <tr class="group hover:bg-blue-50/30 transition-colors"
                                            :class="parseFloat(item.qty) <= 0 ? 'opacity-40' : ''">

                                            {{-- Produk --}}
                                            <td class="px-4 py-2.5">
                                                <input type="hidden" :name="`items[${idx}][product_id]`" :value="item.product_id">

                                                {{-- From doc: read-only --}}
                                                <template x-if="item.from_doc">
                                                    <div>
                                                        <div class="font-semibold text-gray-800 text-sm" x-text="item.name"></div>
                                                        <div class="text-[10px] text-gray-400 mt-0.5" x-text="item.sku"></div>
                                                    </div>
                                                </template>

                                                {{-- Manual: searchable --}}
                                                <template x-if="!item.from_doc">
                                                    <div class="relative" @click.outside="item.showDrop = false">
                                                        <input type="text" x-model="item.productQuery"
                                                            @input.debounce.300ms="searchProduct(idx)"
                                                            @focus="item.showDrop = true"
                                                            placeholder="Cari produk (SKU/nama)..."
                                                            autocomplete="off"
                                                            class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                                        <div x-show="item.showDrop && item.results.length > 0"
                                                             class="absolute bg-white border border-gray-200 w-64 mt-1 rounded-xl shadow-lg z-20 max-h-48 overflow-y-auto">
                                                            <template x-for="p in item.results" :key="p.id">
                                                                <div @click="selectProduct(idx, p)"
                                                                     class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                                    <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                                                    <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </td>

                                            {{-- Qty Asli --}}
                                            <td class="px-3 py-2.5 text-center">
                                                <span class="text-gray-500 font-semibold text-sm" x-text="item.qty_original > 0 ? item.qty_original : '—'"></span>
                                            </td>

                                            {{-- Qty Klaim --}}
                                            <td class="px-3 py-2.5 text-center">
                                                <input type="number"
                                                       :name="`items[${idx}][qty]`"
                                                       x-model="item.qty"
                                                       :max="item.qty_original > 0 ? item.qty_original : undefined"
                                                       min="0" step="0.01"
                                                       @input="onQtyInput(idx)"
                                                       class="w-20 border-2 text-center rounded-lg px-2 py-1.5 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-400 transition"
                                                       :class="{
                                                           'border-red-300 bg-red-50': item.qty_error,
                                                           'border-blue-300 bg-blue-50': parseFloat(item.qty) > 0 && !item.qty_error,
                                                           'border-gray-200 bg-white': !parseFloat(item.qty) && !item.qty_error
                                                       }">
                                                <div class="text-[9px] text-red-500 font-bold mt-0.5" x-show="item.qty_error" x-text="item.qty_error"></div>
                                            </td>

                                            {{-- Masalah --}}
                                            <td class="px-3 py-2.5">
                                                <input type="text"
                                                       :name="`items[${idx}][issue_description]`"
                                                       x-model="item.issue"
                                                       placeholder="Jelaskan masalah..."
                                                       class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white">
                                            </td>

                                            {{-- Hapus --}}
                                            <td class="px-2 py-2.5 text-center">
                                                <button type="button" @click="removeItem(idx)"
                                                        class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition opacity-0 group-hover:opacity-100">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="items.length === 0">
                        <div class="text-center py-10 text-gray-400 text-sm border-2 border-dashed border-gray-100 rounded-xl">
                            <div class="text-2xl mb-2">📦</div>
                            <div x-show="!selectedDoc">Pilih dokumen referensi — produk akan terisi otomatis.</div>
                            <div x-show="selectedDoc">Tidak ada produk. Klik "+ Tambah Manual".</div>
                        </div>
                    </template>
                </div>

            </div>

            {{-- RIGHT SIDEBAR --}}
            <div class="col-span-4 space-y-4">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden sticky top-4">

                    {{-- Journal Preview --}}
                    <div class="p-5 border-b border-gray-100">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="w-7 h-7 bg-orange-500 text-white rounded-lg flex items-center justify-center text-xs font-black">3</span>
                            <h3 class="font-bold text-gray-700">Preview Jurnal</h3>
                        </div>

                        <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-[11px] font-mono leading-relaxed relative">
                            <div class="absolute top-2 right-3 text-[8px] text-gray-400 font-bold uppercase tracking-widest">Saat Posting Garansi</div>

                            <div class="space-y-1.5 mb-2" x-show="journalPreview.cogsEstimate > 0">
                                <div class="flex justify-between">
                                    <span class="font-bold text-blue-600">Dr. 1131 Persediaan Perbaikan</span>
                                    <span class="font-black text-gray-800" x-text="`Rp ${fmt(journalPreview.cogsEstimate)}`"></span>
                                </div>
                                <div class="flex justify-between pl-4">
                                    <span class="text-gray-500 italic">Cr. 1132 Beban Stok Garansi</span>
                                    <span class="font-black text-gray-700" x-text="`Rp ${fmt(journalPreview.cogsEstimate)}`"></span>
                                </div>
                            </div>

                            <div x-show="journalPreview.cogsEstimate > 0" class="mt-2 pt-2 border-t border-gray-200 space-y-1">
                                <div class="text-[9px] text-gray-400 font-bold uppercase tracking-widest mt-1">Saat Produksi Selesai</div>
                                <div class="flex justify-between text-[10px]">
                                    <span class="font-bold text-blue-600">Dr. 1130 Persediaan</span>
                                    <span class="text-gray-500 italic">output produksi</span>
                                </div>
                                <div class="flex justify-between pl-4 text-[10px]">
                                    <span class="text-gray-500 italic">Cr. 1140 WIP</span>
                                </div>
                                <div class="text-[9px] text-gray-400 italic border-t border-gray-200 pt-1 mt-1">* Estimasi berdasarkan data dokumen. Nilai aktual dihitung saat proses.</div>
                            </div>

                            <div x-show="journalPreview.cogsEstimate === 0" class="text-gray-300 italic text-center text-[10px] py-2">
                                Menunggu pilih dokumen & produk...
                            </div>
                        </div>
                    </div>

                    {{-- Info --}}
                    <div class="p-5 border-b border-gray-100">
                        <h3 class="font-bold text-gray-700 mb-3">Alur Garansi Perbaikan</h3>
                        <div class="space-y-2.5 text-xs text-gray-600">
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 text-blue-500">①</span>
                                <span><strong>Terima Barang:</strong> Stok masuk ke 1131 Persediaan Perbaikan.</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 text-green-500">②</span>
                                <span><strong>Posting Garansi:</strong> Jurnal Dr 1131 Persediaan / Cr 1132 Beban Stok Garansi.</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 text-orange-500">③</span>
                                <span><strong>Produksi Perbaikan:</strong> Dr WIP / Cr 1131. Selesai → Dr 1130 / Cr WIP.</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 text-purple-500">④</span>
                                <span><strong>Post SJ:</strong> Dr 1132 (HPP customer) + Dr 6106 (biaya perbaikan) / Cr 1130.</span>
                            </div>
                            <div class="flex items-start gap-2 pt-2 border-t border-gray-100">
                                <span class="mt-0.5 text-gray-400">ℹ</span>
                                <span class="text-gray-400">Saldo customer <strong>tidak berubah</strong>.</span>
                            </div>
                        </div>
                    </div>

                    {{-- Buttons --}}
                    <div class="p-5 flex flex-col gap-3">
                        @if(!isset($warranty))
                            <div class="flex gap-2">
                                <button type="button" @click="saveDraft()"
                                        :disabled="!canSubmit()"
                                        class="flex-1 py-3 rounded-xl font-bold text-sm transition-all border border-gray-200 flex items-center justify-center gap-2"
                                        :class="canSubmit() ? 'bg-white text-gray-700 hover:bg-gray-50' : 'bg-gray-50 text-gray-300 cursor-not-allowed'">
                                    💾 Simpan Draft
                                </button>
                                <button type="button" @click="confirmPost()"
                                        :disabled="!canSubmit()"
                                        class="flex-[1.5] py-3 rounded-xl font-black text-sm transition-all flex items-center justify-center gap-2 shadow-lg"
                                        :class="canSubmit() ? 'bg-green-600 hover:bg-green-700 text-white shadow-green-200 active:scale-95' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Posting
                                </button>
                            </div>
                        @else
                            <button type="submit"
                                    :disabled="!canSubmit()"
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition active:scale-95 disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed">
                                Simpan Perubahan
                            </button>
                        @endif
                        <a href="{{ route('sales.warranty.index') }}"
                           class="w-full text-center border border-gray-200 text-gray-600 hover:bg-gray-50 py-2.5 rounded-xl text-sm font-semibold transition">
                            Batal
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </form>

    {{-- Confirm Post Modal --}}
    <div x-show="showConfirm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="fixed inset-0 z-50 flex items-center justify-center p-4"
         style="display:none">
        <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" @click="showConfirm = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 z-10">
            <div class="text-center mb-5">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3 text-green-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h2 class="text-lg font-black text-gray-800">Konfirmasi Posting Garansi</h2>
                <p class="text-gray-500 text-sm mt-1">Barang akan langsung diterima & stok masuk ke gudang perbaikan.</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-4 mb-5 space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Customer</span>
                    <span class="font-bold text-gray-800" x-text="customerQuery"></span>
                </div>
                <div class="flex justify-between" x-show="selectedDoc">
                    <span class="text-gray-500" x-text="refType === 'invoice' ? 'Invoice' : 'Sales Order'"></span>
                    <span class="font-bold text-gray-800" x-text="selectedDoc?.number"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Total Item</span>
                    <span class="font-bold text-gray-800" x-text="`${items.filter(i => i.product_id).length} produk`"></span>
                </div>
                <div class="flex justify-between border-t border-gray-200 pt-2">
                    <span class="text-gray-500">Aksi</span>
                    <span class="font-black text-green-600">Buat + Terima Barang</span>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="button" @click="showConfirm = false"
                        class="flex-1 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">
                    Batal
                </button>
                <button type="button" @click="doPost()"
                        class="flex-1 py-2.5 bg-green-600 text-white rounded-xl text-sm font-black hover:bg-green-700 transition active:scale-95 shadow-lg shadow-green-200">
                    Ya, Posting
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function warrantyForm(initialData = null) {
    return {
        formStatus: 'draft',
        showConfirm: false,

        // Customer
        customerId:           initialData?.customer_id ?? null,
        customerQuery:        initialData?.customer?.name ?? '',
        customerResults:      [],
        showCustomerDropdown: false,

        // Reference type
        refType: initialData?.invoice_id ? 'invoice' : (initialData?.sales_order_id ? 'so' : 'none'),

        // Documents
        documents:    [],
        selectedDocId: initialData?.invoice_id ?? initialData?.sales_order_id ?? null,
        selectedDoc:  null,
        docQuery:     initialData?.invoice?.invoice_number ?? initialData?.sales_order?.order_number ?? '',
        showDocDropdown: false,
        loadingDocs:  false,

        // Warranty type (always repair)
        warrantyType: 'repair',

        // Items
        items: [],

        // Journal preview
        journalPreview: { cogsEstimate: 0 },

        get filteredDocs() {
            if (!this.docQuery) return this.documents;
            return this.documents.filter(d => d.number.toLowerCase().includes(this.docQuery.toLowerCase()));
        },

        async init() {
            this.$watch('items', () => this.calcPreview(), { deep: true });
            this.$watch('warrantyType', () => this.calcPreview());

            if (initialData) {
                if (this.customerId && this.refType !== 'none') {
                    await this.loadDocuments();
                    this.selectedDoc = this.documents.find(d => d.id == this.selectedDocId) ?? null;
                }

                @if(isset($warranty))
                    @foreach($warranty->items as $item)
                    this.items.push({
                        from_doc:     false,
                        product_id:   {{ $item->product_id }},
                        productQuery: '{{ addslashes(($item->product->sku ?? '') . ' - ' . ($item->product->name ?? '')) }}',
                        sku:          '{{ addslashes($item->product->sku ?? '') }}',
                        name:         '{{ addslashes($item->product->name ?? '') }}',
                        qty_original: 0,
                        qty:          {{ $item->qty }},
                        qty_error:    null,
                        issue:        '{{ addslashes($item->issue_description ?? '') }}',
                        cogs_total:   0,
                        results:      [],
                        showDrop:     false,
                    });
                    @endforeach
                @endif

                this.calcPreview();
            } else {
                this.addItem();
            }
        },

        async searchCustomer() {
            if (this.customerQuery.length < 2) { this.customerResults = []; return; }
            const res = await fetch(`/erp/api/customers/search?q=${encodeURIComponent(this.customerQuery)}`);
            this.customerResults = await res.json();
            this.showCustomerDropdown = true;
        },

        selectCustomer(c) {
            this.customerId           = c.id;
            this.customerQuery        = c.name;
            this.showCustomerDropdown = false;
            this.resetDoc();
            if (this.refType !== 'none') this.loadDocuments();
        },

        async onRefTypeChange() {
            this.resetDoc();
            if (this.refType !== 'none' && this.customerId) {
                await this.loadDocuments();
            }
        },

        async loadDocuments() {
            if (!this.customerId || this.refType === 'none') return;
            this.loadingDocs = true;
            try {
                const url = this.refType === 'invoice'
                    ? `{{ route('sales.ajax.warranty.invoices') }}?customer_id=${this.customerId}`
                    : `{{ route('sales.ajax.warranty.orders') }}?customer_id=${this.customerId}`;
                const res = await fetch(url);
                this.documents = await res.json();
            } finally {
                this.loadingDocs = false;
            }
        },

        selectDoc(doc) {
            this.docQuery        = doc.number;
            this.selectedDocId   = doc.id;
            this.selectedDoc     = doc;
            this.showDocDropdown = false;
            this.fillFromDoc();
        },

        resetDoc() {
            this.documents     = [];
            this.selectedDocId = null;
            this.selectedDoc   = null;
            this.docQuery      = '';
            this.items         = [];
            this.calcPreview();
        },

        fillFromDoc() {
            if (!this.selectedDoc?.items?.length) { this.items = []; return; }
            this.items = this.selectedDoc.items.map(i => ({
                from_doc:     true,
                product_id:   i.product_id,
                sku:          i.sku,
                name:         i.name,
                qty_original: i.qty,
                qty:          i.qty,   // default = semua qty
                qty_error:    null,
                issue:        '',
                cogs_total:   i.cogs_total ?? 0,
            }));
            this.calcPreview();
        },

        addManualItem() {
            this.items.push({
                from_doc:     false,
                product_id:   null,
                productQuery: '',
                sku:          '',
                name:         '',
                qty_original: 0,
                qty:          1,
                qty_error:    null,
                issue:        '',
                cogs_total:   0,
                results:      [],
                showDrop:     false,
            });
        },

        removeItem(idx) {
            this.items.splice(idx, 1);
            this.calcPreview();
        },

        onQtyInput(idx) {
            const item = this.items[idx];
            const qty  = parseFloat(item.qty) || 0;
            if (item.qty_original > 0 && qty > item.qty_original) {
                item.qty_error = `Maks ${item.qty_original}`;
                item.qty       = item.qty_original;
            } else {
                item.qty_error = null;
            }
            this.calcPreview();
        },

        async searchProduct(idx) {
            const item = this.items[idx];
            if (!item || item.from_doc) return;
            const q = item.productQuery;
            if (q.length < 2) { item.results = []; return; }
            const res = await fetch(`/erp/inventory/products/search?q=${encodeURIComponent(q)}`);
            item.results  = await res.json();
            item.showDrop = true;
        },

        selectProduct(idx, p) {
            const item         = this.items[idx];
            item.product_id    = p.id;
            item.sku           = p.sku;
            item.name          = p.name;
            item.productQuery  = `${p.sku} - ${p.name}`;
            item.results       = [];
            item.showDrop      = false;
        },

        calcPreview() {
            let cogsEstimate = 0;
            this.items.forEach(item => {
                const qty = parseFloat(item.qty) || 0;
                if (qty > 0 && item.cogs_total > 0 && item.qty_original > 0) {
                    const ratio = qty / item.qty_original;
                    cogsEstimate += item.cogs_total * ratio;
                }
            });
            this.journalPreview = { cogsEstimate: Math.round(cogsEstimate * 100) / 100 };
        },

        canSubmit() {
            if (!this.customerId) return false;
            const hasValid = this.items.some(i => i.product_id && parseFloat(i.qty) > 0);
            const hasError = this.items.some(i => i.qty_error);
            return hasValid && !hasError;
        },

        saveDraft() {
            if (!this.canSubmit()) return;
            this.formStatus = 'draft';
            this.$nextTick(() => document.getElementById('warrantyForm').submit());
        },

        confirmPost() {
            if (!this.canSubmit()) return;
            this.formStatus  = 'post';
            this.showConfirm = true;
        },

        doPost() {
            this.showConfirm = false;
            document.getElementById('warrantyForm').submit();
        },

        fmt(n) {
            if (!n && n !== 0) return '0';
            return Math.round(n).toLocaleString('id-ID');
        },
    };
}
</script>
@endpush
