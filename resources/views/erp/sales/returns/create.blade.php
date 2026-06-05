@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="returForm({{ isset($return) ? $return->load('items', 'customer')->toJson() : 'null' }})" x-init="init()">

    {{-- PAGE HEADER --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium mb-1">
                <a href="{{ route('sales.returns.index') }}" class="hover:text-blue-600 transition">Retur</a>
                <span>›</span>
                <span class="text-gray-600">Buat Retur Baru</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">
                Form Retur Pelanggan
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">Retur akan membalik penjualan & menambah saldo Kelebihan Bayar Pelanggan (2106).</p>
        </div>
        <a href="{{ route('sales.returns.index') }}"
           class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 hover:bg-gray-50 bg-white px-4 py-2 rounded-xl text-sm font-semibold transition-all">
            ← Kembali
        </a>
    </div>

    {{-- ALERT: Error --}}

    <form id="returnForm" method="POST" action="{{ route('sales.returns.store') }}">
        @csrf
        <input type="hidden" name="return_id" :value="returnId">
        <input type="hidden" name="status" :value="formStatus">

        <div class="grid grid-cols-12 gap-5">

            {{-- LEFT COLUMN --}}
            <div class="col-span-8 space-y-4">

                {{-- ═══ SECTION 1: HEADER / DOKUMEN SELECTOR ═══ --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-2 mb-5">
                        <span class="w-7 h-7 bg-blue-600 text-white rounded-lg flex items-center justify-center text-xs font-black">1</span>
                        <h3 class="font-bold text-gray-700">Pilih Pelanggan & Faktur</h3>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mb-4">
                        {{-- Customer Search --}}
                        <div class="col-span-1 border-gray-200 relative" @click.outside="showCustomerDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Pelanggan <span class="text-red-500">*</span></label>
                            
                            <input type="text"
                                x-model="customerQuery"
                                @input.debounce.300ms="searchCustomer()"
                                @focus="showCustomerDropdown = true; if(customerQuery.length >= 2) searchCustomer()"
                                placeholder="Cari pelanggan..."
                                autocomplete="off"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">

                            <input type="hidden" name="customer_id" :value="customerId">
                            
                            <div x-show="showCustomerDropdown && customerResults.length > 0" x-transition class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-60 overflow-y-auto">
                                <template x-for="c in customerResults" :key="c.id">
                                    <div @click="selectCustomer(c)"
                                        class="px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-blue-50 hover:text-blue-700 cursor-pointer border-b border-gray-100 last:border-0 transition-colors"
                                        x-text="c.name">
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Jenis Retur --}}
                        <div class="col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Jenis Retur</label>
                            <select x-model="returnType" @change="onReturnTypeChange()" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                <option value="invoice">🛍️ Dari Faktur</option>
                                <option value="so">📋 Dari Sales Order</option>
                            </select>
                        </div>

                        {{-- Document Selector Search --}}
                        <div class="col-span-1 relative" x-show="documents.length > 0" x-transition @click.outside="showDocDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5" x-text="returnType === 'invoice' ? 'Faktur *' : 'Sales Order *'"></label>
                            
                            <input type="text"
                                x-model="docQuery"
                                @input="showDocDropdown = true"
                                @focus="showDocDropdown = true"
                                placeholder="Cari nomor document..."
                                autocomplete="off"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                            
                            <input type="hidden" name="invoice_id" :value="returnType === 'invoice' ? selectedDocId : ''">
                            <input type="hidden" name="sales_order_id" :value="returnType === 'so' ? selectedDocId : ''">

                            <div x-show="showDocDropdown" x-transition class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-60 overflow-y-auto">
                                <template x-for="doc in filteredDocs" :key="doc.id">
                                    <div @click="selectDoc(doc)"
                                        class="px-4 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors">
                                        <div class="text-sm font-bold text-gray-800" x-text="doc.number"></div>
                                        <div class="text-xs text-gray-500 flex justify-between mt-0.5">
                                            <span x-text="doc.date"></span>
                                            <span class="font-semibold text-gray-700" x-text="`Rp ${formatNumber(doc.grand_total)}`"></span>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="filteredDocs.length === 0" class="px-4 py-3 text-sm text-gray-500 text-center italic">
                                    Tidak ditemukan
                                </div>
                            </div>
                        </div>

                        {{-- No documents message --}}
                        <div class="col-span-3" x-show="customerId && documents.length === 0 && !loadingDocs" x-transition>
                            <div class="text-center py-4 bg-amber-50 rounded-xl border border-amber-100">
                                <span class="text-amber-500 font-semibold text-sm" x-text="returnType === 'invoice' ? '⚠️ Tidak ada faktur posted/partial untuk pelanggan ini.' : '⚠️ Tidak ada Sales Order aktif untuk pelanggan ini.'"></span>
                            </div>
                        </div>

                        {{-- Loading state --}}
                        <div class="col-span-3" x-show="loadingDocs" x-transition>
                            <div class="flex items-center gap-2 text-blue-500 text-sm py-3">
                                <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-text="returnType === 'invoice' ? 'Memuat invoice...' : 'Memuat Sales Order...'"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Document Badge Info --}}
                    <div x-show="selectedDoc" x-transition class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-4 flex items-start gap-4">
                        <div class="bg-blue-600 text-white rounded-lg p-2 mt-0.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-black text-blue-800 text-sm" x-text="selectedDoc?.number"></span>
                                <span class="bg-green-100 text-green-700 text-[10px] font-black px-2 py-0.5 rounded-full uppercase" x-text="selectedDoc?.status"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-xs text-gray-500">
                                <div>Tanggal: <span class="text-gray-700 font-semibold" x-text="selectedDoc?.date"></span></div>
                                <div>Total: <span class="text-gray-700 font-semibold" x-text="`Rp ${formatNumber(selectedDoc?.grand_total)}`"></span></div>
                                <div>Item: <span class="text-gray-700 font-semibold" x-text="`${selectedDoc?.items?.length ?? 0} produk`"></span></div>
                            </div>
                        </div>
                    </div>

                    {{-- Return Date --}}
                    <div class="mt-4" x-show="selectedDoc" x-transition>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Tanggal Retur <span class="text-red-500">*</span></label>
                        <input type="date"
                               name="return_date"
                               x-model="returnDate"
                               class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                               :max="today">
                    </div>
                </div>

                {{-- ═══ SECTION 2: ITEM LIST ═══ --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6" x-show="selectedDoc" x-transition>
                    <div class="flex items-center justify-between mb-5">
                        <div class="flex items-center gap-2">
                            <span class="w-7 h-7 bg-purple-600 text-white rounded-lg flex items-center justify-center text-xs font-black">2</span>
                            <h3 class="font-bold text-gray-700">Item yang Diretur</h3>
                        </div>
                        <div class="text-xs text-gray-400 font-medium">
                            <span x-text="items.filter(i => parseFloat(i.qty_return) > 0).length"></span> item dipilih
                        </div>
                    </div>

                    {{-- Legend --}}
                    <div class="flex items-center gap-4 mb-4 text-xs font-semibold text-gray-400">
                        <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-green-400"></span> Kondisi Utuh → Stok kembali + HPP reverse</div>
                        <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-400"></span> Rusak → Tidak masuk stok</div>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-gray-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100">
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Produk</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-24" x-text="returnType === 'invoice' ? 'Qty Faktur' : 'Qty Order'"></th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-28">Qty Retur</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Kondisi</th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Nilai Retur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <template x-for="(item, index) in items" :key="item.id">
                                    <tr class="group transition-colors"
                                        :class="{
                                            'bg-green-50/50': item.condition === 'good' && parseFloat(item.qty_return) > 0,
                                            'bg-red-50/50':   item.condition === 'damaged' && parseFloat(item.qty_return) > 0,
                                            'opacity-50':     parseFloat(item.qty_return) === 0
                                        }">
                                        {{-- Product --}}
                                        <td class="px-4 py-3">
                                            <input type="hidden" :name="`items[${index}][invoice_item_id]`" :value="item.id">
                                            <input type="hidden" :name="`items[${index}][condition]`"      :value="item.condition">
                                            <div class="font-semibold text-gray-800" x-text="item.name"></div>
                                            <div class="text-[10px] text-gray-400 mt-0.5" x-show="item.qty > 0">
                                                Harga Net: Rp <span x-text="formatNumber(item.subtotal / item.qty)"></span>
                                                <span class="ml-1 text-[9px] bg-gray-100 px-1 rounded italic">incl. diskon</span>
                                            </div>
                                        </td>

                                        {{-- Qty Invoice --}}
                                        <td class="px-4 py-3 text-center">
                                            <span class="font-black text-gray-700" x-text="item.qty"></span>
                                        </td>

                                        {{-- Qty Retur --}}
                                        <td class="px-4 py-3 text-center">
                                            <div class="relative">
                                                <input type="number"
                                                    :name="`items[${index}][qty]`"
                                                    x-model="item.qty_return"
                                                    :max="item.qty"
                                                    min="0"
                                                    step="0.01"
                                                    @input="onQtyChange(index)"
                                                    @blur="validateQty(index)"
                                                    class="w-20 border-2 text-center rounded-lg px-2 py-1.5 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-400 transition"
                                                    :class="{
                                                        'border-red-400 bg-red-50': item.qty_error,
                                                        'border-green-300 bg-green-50': parseFloat(item.qty_return) > 0 && !item.qty_error,
                                                        'border-gray-200': !parseFloat(item.qty_return) && !item.qty_error
                                                    }">
                                                <div class="text-[9px] text-red-500 font-bold mt-0.5" x-show="item.qty_error" x-text="item.qty_error"></div>
                                            </div>
                                        </td>

                                        {{-- Kondisi --}}
                                        <td class="px-4 py-3 text-center">
                                            <select x-model="item.condition"
                                                    @change="onConditionChange(index)"
                                                    class="border rounded-lg px-2 py-1.5 text-xs font-bold focus:outline-none focus:ring-2 transition"
                                                    :class="{
                                                        'border-green-300 text-green-700 bg-green-50':  item.condition === 'good',
                                                        'border-yellow-300 text-yellow-700 bg-yellow-50': item.condition === 'repair',
                                                        'border-red-300   text-red-700   bg-red-50':    item.condition === 'damaged'
                                                    }">
                                                <option value="good">🟢 Utuh</option>
                                                <option value="repair">🟡 Perbaikan</option>
                                                <option value="damaged">🔴 Tidak Dapat Diperbaiki</option>
                                            </select>
                                        </td>

                                        {{-- Nilai Retur --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="font-black text-gray-700" x-text="parseFloat(item.qty_return) > 0 ? `Rp ${formatNumber(getLineValue(item))}` : '—'"></div>
                                            <div class="text-[9px] text-gray-400 mt-0.5" x-show="parseFloat(item.qty_return) > 0">
                                                Net setelah diskon
                                            </div>
                                        </td>
                                    </tr>
                                </template>

                                {{-- Empty state --}}
                                <tr x-show="items.length === 0">
                                    <td colspan="5" class="px-4 py-8 text-center text-gray-300 font-medium text-sm">
                                        Pilih invoice terlebih dahulu
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            {{-- RIGHT COLUMN --}}
            <div class="col-span-4 space-y-4">

                {{-- SALDO CUSTOMER REMOVED FOR CLEANER UI --}}

                {{-- ═══ RETURN SUMMARY (UPGRADE: TRANSPARENT ACCOUNTING) ═══ --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" x-show="selectedDoc" x-transition>
                    <div class="p-5">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="w-7 h-7 bg-orange-500 text-white rounded-lg flex items-center justify-center text-xs font-black">3</span>
                            <h3 class="font-bold text-gray-700">Ringkasan Retur</h3>
                        </div>

                        <div class="space-y-3">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-500 font-medium">Subtotal (Net)</span>
                                <span class="font-bold text-gray-700">Rp <span x-text="formatNumber(summary.net)"></span></span>
                            </div>
                            <div class="border-t border-gray-100 pt-3 flex justify-between items-center">
                                <span class="font-black text-gray-800">Total Retur</span>
                                <span class="font-black text-blue-600 text-lg">Rp <span x-text="formatNumber(summary.net)"></span></span>
                            </div>
                        </div>

                        {{-- 🔥 PREVIEW JURNAL (DINAMIS & CLEAN) --}}
                        <div class="mt-4 border-t border-gray-100 pt-4">
                            <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                Preview Jurnal
                            </h4>

                            <div class="bg-white border border-gray-200 rounded-xl p-4 text-[11px] font-mono leading-relaxed relative">
                                <div class="absolute top-2 right-3 text-[8px] text-gray-400 font-bold uppercase tracking-widest">Live Preview</div>
                                
                                {{-- Core Ledger (Revenue or Advance reversal) --}}
                                <div class="space-y-1.5 mb-3" x-show="journalPreview.revenue">
                                    <div class="flex justify-between">
                                        <template x-if="journalPreview.revenue">
                                            <span class="font-bold" :class="journalPreview.revenue.debitClass" x-text="journalPreview.revenue.debitLabel"></span>
                                        </template>
                                        <span class="text-gray-900 font-black" x-text="formatNumber(summary.net)"></span>
                                    </div>
                                    <div class="flex justify-between pl-4">
                                        <template x-if="journalPreview.revenue">
                                            <span class="text-gray-500 italic" x-text="journalPreview.revenue.creditLabel"></span>
                                        </template>
                                        <span class="text-gray-700 font-black" x-text="formatNumber(summary.net)"></span>
                                    </div>
                                </div>

                                {{-- Stock / Condition Impact Ledger --}}
                                <template x-if="journalPreview.conditions.length > 0">
                                    <div class="border-t border-gray-100 pt-3 space-y-2">
                                        <template x-for="group in journalPreview.conditions" :key="group.condition">
                                            <div class="space-y-1.5">
                                                <div class="flex justify-between">
                                                    <span class="font-bold" :class="group.debitClass" x-text="group.debitLabel"></span>
                                                    <span class="text-gray-900 font-black" x-text="formatNumber(group.amount)"></span>
                                                </div>
                                                <div class="flex justify-between pl-4">
                                                    <span class="text-gray-500 italic" x-text="group.creditLabel"></span>
                                                    <span class="text-gray-700 font-black" x-text="formatNumber(group.amount)"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <div x-show="summary.net === 0" class="text-gray-300 italic text-center text-[10px] py-1">
                                    Menunggu input data...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ═══ UX WARNINGS ═══ --}}
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5" x-show="selectedDoc" x-transition>
                    <div class="font-bold text-amber-700 text-sm mb-2 flex items-center gap-1.5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.268 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        EFEK RETUR:
                    </div>
                    <ul class="space-y-2 text-xs text-amber-600 font-medium">
                        <li class="flex items-start gap-2" x-show="returnType === 'invoice'">
                            <span class="bg-amber-200 text-amber-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">1</span>
                            <span>Penjualan (Revenue) akan dibalik/dikurangi.</span>
                        </li>
                        <li class="flex items-start gap-2" x-show="returnType === 'so'">
                            <span class="bg-indigo-200 text-indigo-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">1</span>
                            <span>Uang Muka (Advance) akan dibalik ke saldo pelanggan.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="bg-amber-200 text-amber-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">2</span>
                            <span x-text="`Saldo ${getReturnAccountName()} pelanggan akan bertambah.`"></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="bg-amber-200 text-amber-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">3</span>
                            <span>Barang dipisahkan sesuai kondisi: utuh ke persediaan, perbaikan ke akun perbaikan, rusak ke beban kerugian retur.</span>
                        </li>
                        <li class="flex items-start gap-2 pt-1 border-t border-amber-200/50">
                            <span class="text-red-500 font-black">✖</span>
                            <span class="text-red-600 font-black uppercase tracking-tight">Data permanen setelah posting.</span>
                        </li>
                    </ul>
                </div>

                {{-- ═══ ACTION BUTTONS ═══ --}}
                <div class="flex flex-col gap-3" x-show="selectedDoc" x-transition>
                    
                    <div class="flex gap-2">
                        <button type="button"
                                @click="saveDraft()"
                                :disabled="!canSubmit()"
                                class="flex-1 py-3.5 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2 border border-gray-200"
                                :class="canSubmit()
                                    ? 'bg-white text-gray-700 hover:bg-gray-50'
                                    : 'bg-gray-50 text-gray-300 cursor-not-allowed'">
                            <span x-text="returnId ? '💾 Perbarui Draf' : '💾 Simpan Draf'"></span>
                        </button>

                        <button type="button"
                                @click="confirmAndSubmit()"
                                :disabled="!canSubmit()"
                                class="flex-[1.5] py-3.5 rounded-xl font-black text-sm transition-all flex items-center justify-center gap-2 shadow-lg"
                                :class="canSubmit()
                                    ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-blue-200 active:scale-95'
                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            POST RETUR
                        </button>
                    </div>

                    <a href="{{ route('sales.returns.index') }}"
                       class="w-full py-3 rounded-xl font-semibold text-sm text-gray-400 hover:text-gray-600 transition-all flex items-center justify-center">
                        Batal & Kembali
                    </a>
                </div>

            </div>
        </div>
    </form>

    {{-- ═══ CONFIRM MODAL ═══ --}}
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
                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3 text-blue-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582M20 20v-5h-.581M4.582 9A7.001 7.001 0 0112 5c2.45 0 4.62 1.25 5.918 3M19.418 15A7.001 7.001 0 0112 19c-2.45 0-4.62-1.25-5.918-3"/>
                    </svg>
                </div>
                <h2 class="text-lg font-black text-gray-800">Konfirmasi Retur</h2>
                <p class="text-gray-500 text-sm mt-1">Tindakan ini tidak dapat dibatalkan.</p>
            </div>

            <div class="bg-gray-50 rounded-xl p-4 mb-5 space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Pelanggan</span>
                    <span class="font-bold text-gray-800" x-text="selectedInvoice?.number ? getCustomerName() : '—'"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500" x-text="returnType === 'invoice' ? 'Faktur' : 'Sales Order'"></span>
                    <span class="font-bold text-gray-800" x-text="selectedDoc?.number"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Total Retur</span>
                    <span class="font-black text-blue-600">Rp <span x-text="formatNumber(summary.net)"></span></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500" x-text="`Saldo ${getReturnAccountName()} naik menjadi`"></span>
                    <span class="font-black text-green-600">Rp <span x-text="formatNumber(customerBalance + summary.net)"></span></span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="showConfirm = false"
                        class="flex-1 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">
                    Batal
                </button>
                <button type="button" @click="doSubmit()"
                        class="flex-1 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-black hover:bg-blue-700 transition active:scale-95 shadow-lg shadow-blue-200">
                    Ya, POST Retur
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function returForm(initialData = null) {
    return {
        // State
        returnId: initialData?.id || null,
        formStatus: 'posted',
        customerId: initialData?.customer_id || null,
        customerName: initialData?.customer?.name || '',
        returnType: initialData?.invoice_id ? 'invoice' : (initialData?.sales_order_id ? 'so' : 'invoice'),
        documents: [],
        selectedDocId: initialData?.invoice_id || initialData?.sales_order_id || null,
        selectedDoc: null,
        items: [],
        returnDate: initialData?.return_date ? initialData.return_date.split('T')[0] : '{{ now()->format("Y-m-d") }}',
        today: '{{ now()->format("Y-m-d") }}',
        customerBalance: initialData?.customer?.credit_balance || 0,
        isMarketplace: initialData?.customer?.is_marketplace || false,
        marketplaceHoldName: initialData?.customer?.marketplace_hold_name || '',

        // UI
        loadingDocs: false,
        loadingBalance: false,
        showConfirm: false,

        // Search UI State
        customerQuery: initialData?.customer?.name || '',
        customerResults: [],
        showCustomerDropdown: false,
        docQuery: initialData?.invoice?.invoice_number || initialData?.sales_order?.order_number || '',
        showDocDropdown: false,

        // Live Search Methods
        async searchCustomer() {
            if (this.customerQuery.length < 2) {
                this.customerResults = [];
                return;
            }
            try {
                let res = await fetch(`/erp/api/customers/search?q=${this.customerQuery}`);
                this.customerResults = await res.json();
            } catch (e) {
                console.error("Gagal memuat customer", e);
            }
        },
        selectCustomer(customer) {
            this.customerQuery = customer.name;
            this.showCustomerDropdown = false;
            this.docQuery = ''; // Reset doc search
            this.isMarketplace = customer.is_marketplace || false;
            this.marketplaceHoldName = customer.marketplace_hold_name || '';
            this.onCustomerChange(customer.id);
        },
        get filteredDocs() {
            if (!this.docQuery) return this.documents;
            return this.documents.filter(d => d.number.toLowerCase().includes(this.docQuery.toLowerCase()));
        },
        selectDoc(doc) {
            this.docQuery = doc.number;
            this.showDocDropdown = false;
            this.selectedDocId = doc.id;
            this.onDocumentChange(doc.id);
        },

        summary: {
            net: 0,
            cogs: 0,
            conditionTotals: {
                good: 0,
                repair: 0,
                damaged: 0,
            },
        },
        journalPreview: {
            revenue: null,
            conditions: [],
        },

        async init() {
            // Watch for changes to recalculate
            this.$watch('items', () => this.calculateSummary(), { deep: true });

            if (this.returnId && initialData) {
                // We are in EDIT mode (from Draft)
                this.loadingDocs = true;
                try {
                    // 1. Load documents for this customer
                    const url = this.returnType === 'invoice' 
                        ? `{{ route('sales.ajax.returns.invoices') }}?customer_id=${this.customerId}`
                        : `{{ route('sales.ajax.returns.orders') }}?customer_id=${this.customerId}`;
                    
                    const res = await fetch(url);
                    this.documents = await res.json();
                    
                    // 2. Select the current document
                    this.selectedDoc = this.documents.find(d => d.id == this.selectedDocId);
                    
                    if (this.selectedDoc) {
                        // 3. Map items from initialData, but merge with selectedDoc items for reference data (like max qty)
                        this.items = this.selectedDoc.items.map(docItem => {
                            const returnItem = initialData.items.find(ri => ri.reference_item_id == docItem.id);
                            return {
                                id:              docItem.id,
                                name:            docItem.name,
                                qty:             docItem.qty,
                                qty_return:      returnItem ? parseFloat(returnItem.qty) : 0,
                                unit_price:      docItem.unit_price,
                                discount_amount: docItem.discount_amount,
                                subtotal:        docItem.subtotal,
                                cogs_total:      docItem.cogs_total,
                                condition:       returnItem ? returnItem.condition : 'good',
                                qty_error:       null,
                            };
                        });
                    }
                    
                    await this.refreshBalance();
                    this.calculateSummary(); // 🔥 TRIGGER INITIAL CALCULATION
                } catch (e) {
                    console.error('Error initializing edit form:', e);
                } finally {
                    this.loadingDocs = false;
                }
            }
        },

        // ── Customer Changed ──────────────────────────────
        async onCustomerChange(id) {
            this.customerId = id || null;
            this.documents = [];
            this.selectedDoc = null;
            this.selectedDocId = null;
            this.items = [];
            this.customerBalance = 0;
            this.summary = {
                net: 0,
                cogs: 0,
                conditionTotals: {
                    good: 0,
                    repair: 0,
                    damaged: 0,
                },
            };
            this.journalPreview = { revenue: null, conditions: [] };
            this.returnId = null; // Reset draft reference if customer changes

            if (!id) return;

            this.loadingDocs    = true;
            this.loadingBalance = true;

            try {
                const url = this.returnType === 'invoice' 
                    ? `{{ route('sales.ajax.returns.invoices') }}?customer_id=${id}`
                    : `{{ route('sales.ajax.returns.orders') }}?customer_id=${id}`;

                const [docRes, balRes] = await Promise.all([
                    fetch(url),
                    fetch(`{{ route('sales.ajax.returns.customer_balance') }}?customer_id=${id}`)
                ]);

                this.documents       = await docRes.json();
                const balData        = await balRes.json();
                this.customerBalance = balData.balance ?? 0;
            } catch (e) {
                console.error('Error loading data:', e);
            } finally {
                this.loadingDocs    = false;
                this.loadingBalance = false;
            }

            // Store name for modal
            this.customerName = this.customerQuery;
        },

        async onReturnTypeChange() {
            if (!this.customerId) return;
            
            this.loadingDocs = true;
            this.documents = [];
            this.selectedDoc = null;
            this.selectedDocId = null;
            this.items = [];
            this.summary = {
                net: 0,
                cogs: 0,
                conditionTotals: {
                    good: 0,
                    repair: 0,
                    damaged: 0,
                },
            };
            this.journalPreview = { revenue: null, conditions: [] };
            this.docQuery = '';

            try {
                const url = this.returnType === 'invoice' 
                    ? `{{ route('sales.ajax.returns.invoices') }}?customer_id=${this.customerId}`
                    : `{{ route('sales.ajax.returns.orders') }}?customer_id=${this.customerId}`;
                
                const res = await fetch(url);
                this.documents = await res.json();
            } catch (e) {
                console.error('Error loading documents:', e);
            } finally {
                this.loadingDocs = false;
            }
        },

        // ── Document Changed ──────────────────────────────
        onDocumentChange(id) {
            this.selectedDocId = id;
            this.selectedDoc   = this.documents.find(d => d.id == id) || null;
            this.items         = [];
            this.summary       = {
                net: 0,
                cogs: 0,
                conditionTotals: {
                    good: 0,
                    repair: 0,
                    damaged: 0,
                },
            };
            this.journalPreview = { revenue: null, conditions: [] };

            if (!this.selectedDoc) return;

            // Map doc items to returnable items
            this.items = this.selectedDoc.items.map(item => ({
                id:           item.id,
                name:         item.name,
                qty:          item.qty,
                qty_return:   0,
                unit_price:   item.unit_price,
                discount_amount: item.discount_amount,
                subtotal:     item.subtotal,
                cogs_total:   item.cogs_total,
                condition:    'good',
                qty_error:    null,
            }));

            this.calculateSummary(); // 🔥 TRIGGER INITIAL CALCULATION
        },

        // ── Qty Validation ──────────────────────────────
        validateQty(index) {
            const item = this.items[index];
            const qty  = parseFloat(item.qty_return) || 0;

            if (qty < 0) {
                item.qty_return = 0;
                item.qty_error  = null;
            } else if (qty > item.qty) {
                item.qty_error  = `Maks ${item.qty}`;
                item.qty_return = item.qty; // auto-cap
            } else {
                item.qty_error  = null;
            }
        },

        onQtyChange(index) {
            this.validateQty(index);
            this.calculateSummary();
        },

        onConditionChange(index) {
            this.calculateSummary();
        },

        // ── Calculate ──────────────────────────────────
        getLineValue(item) {
            const qty   = parseFloat(item.qty_return) || 0;
            if (!qty) return 0;
            const ratio = qty / item.qty;
            return Math.round(item.subtotal * ratio * 100) / 100;
        },

        calculateSummary() {
            let net = 0;
            let cogs = 0;
            const conditionTotals = {
                good: 0,
                repair: 0,
                damaged: 0,
            };

            this.items.forEach(item => {
                const qty = parseFloat(item.qty_return) || 0;
                if (qty > 0) {
                    const lineNet = this.getLineValue(item);
                    net += lineNet;

                    const lineCogs = this.getLineCogs(item);
                    cogs += lineCogs;

                    if (conditionTotals[item.condition] !== undefined) {
                        conditionTotals[item.condition] += lineCogs;
                    }
                }
            });

            this.summary = {
                net: Math.round(net * 100) / 100,
                cogs: Math.round(cogs * 100) / 100,
                conditionTotals: {
                    good: Math.round(conditionTotals.good * 100) / 100,
                    repair: Math.round(conditionTotals.repair * 100) / 100,
                    damaged: Math.round(conditionTotals.damaged * 100) / 100,
                },
            };

            this.journalPreview = this.buildJournalPreview();
        },

        getLineCogs(item) {
            const qty = parseFloat(item.qty_return) || 0;
            const totalQty = parseFloat(item.qty) || 0;
            const totalCogs = parseFloat(item.cogs_total) || 0;

            if (!qty || !totalQty || !totalCogs) {
                return 0;
            }

            return (totalCogs / totalQty) * qty;
        },

        getConditionMeta(condition) {
            const meta = {
                good: {
                    label: 'Persediaan',
                    debitLabel: 'Dr. 1130 Persediaan',
                    creditLabel: 'Cr. 5001 HPP',
                    debitClass: 'text-emerald-600',
                    tone: 'emerald',
                },
                repair: {
                    label: 'Persediaan Perbaikan',
                    debitLabel: 'Dr. 1131 Persediaan Perbaikan',
                    creditLabel: 'Cr. 5001 HPP',
                    debitClass: 'text-amber-600',
                    tone: 'amber',
                },
                damaged: {
                    label: 'Beban Kerugian Retur',
                    debitLabel: 'Dr. 6105 Beban Kerugian Retur',
                    creditLabel: 'Cr. 5001 HPP',
                    debitClass: 'text-red-600',
                    tone: 'red',
                },
            };

            return meta[condition] || meta.good;
        },

        buildJournalPreview() {
            const preview = {
                revenue: null,
                conditions: [],
            };

            if (this.summary.net > 0) {
                preview.revenue = {
                    debitLabel: this.returnType === 'so'
                        ? 'Dr. 2105 Uang Muka Penjualan'
                        : 'Dr. 4001 Penjualan',
                    creditLabel: `Cr. ${this.getReturnAccountName()}`,
                    debitClass: this.returnType === 'so' ? 'text-indigo-600' : 'text-blue-600',
                    amount: this.summary.net,
                };
            }

            ['good', 'repair', 'damaged'].forEach(condition => {
                const amount = this.summary.conditionTotals?.[condition] ?? 0;
                if (amount > 0) {
                    const meta = this.getConditionMeta(condition);
                    preview.conditions.push({
                        condition,
                        amount: Math.round(amount * 100) / 100,
                        ...meta,
                    });
                }
            });

            return preview;
        },

        // ── Validation ─────────────────────────────────
        canSubmit() {
            if (!this.customerId || !this.selectedDoc || !this.returnDate) return false;
            const hasItem = this.items.some(i => parseFloat(i.qty_return) > 0);
            const hasError = this.items.some(i => i.qty_error);
            return hasItem && !hasError && this.summary.net > 0;
        },

        // ── Submit Logic ───────────────────────────────
        
        saveDraft() {
            if (!this.canSubmit()) return;
            this.formStatus = 'draft';
            this.$nextTick(() => {
                document.getElementById('returnForm').submit();
            });
        },

        confirmAndSubmit() {
            if (!this.canSubmit()) return;
            this.formStatus = 'posted';
            this.showConfirm = true;
        },

        doSubmit() {
            this.showConfirm = false;
            document.getElementById('returnForm').submit();
        },

        // ── Helpers ───────────────────────────────────
        formatNumber(n) {
            if (!n && n !== 0) return '0';
            return Math.round(n).toLocaleString('id-ID');
        },

        getCustomerName() {
            return this.customerName;
        },

        getReturnAccountName() {
            if (this.isMarketplace && this.marketplaceHoldName) {
                return this.marketplaceHoldName;
            }
            return '2106 Overpay Customer';
        },

        async refreshBalance() {
            if (!this.customerId) return;
            this.loadingBalance = true;
            try {
                const res  = await fetch(`{{ route('sales.ajax.returns.customer_balance') }}?customer_id=${this.customerId}`);
                const data = await res.json();
                this.customerBalance = data.balance ?? 0;
            } finally {
                this.loadingBalance = false;
            }
        },
    }
}
</script>
@endpush
