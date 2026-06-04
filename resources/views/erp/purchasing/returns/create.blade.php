@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="returForm({{ isset($return) ? $return->load('items', 'supplier', 'purchaseInvoice', 'purchaseOrder')->toJson() : 'null' }})" x-init="init()">

    <div class="flex justify-between items-center mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium mb-1">
                <a href="{{ route('purchasing.returns.index') }}" class="hover:text-blue-600 transition">Retur Pembelian</a>
                <span>›</span>
                <span class="text-gray-600" x-text="returnId ? 'Edit Draft' : 'Buat Retur Baru'"></span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">Form Retur Pembelian</h1>
            <p class="text-xs text-gray-500 mt-0.5">Retur Invoice mengurangi hutang/menambah Piutang Lebih Bayar (1108) sebesar HPP barang. Retur PO memindah DP ke Piutang Lebih Bayar.</p>
        </div>
        <a href="{{ route('purchasing.returns.index') }}"
           class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 hover:bg-gray-50 bg-white px-4 py-2 rounded-xl text-sm font-semibold transition-all">
            ← Kembali
        </a>
    </div>


    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-3.5 rounded-xl mb-5">
            <div class="font-bold text-sm mb-2">Validasi gagal:</div>
            <ul class="list-disc pl-5 text-xs space-y-0.5">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="returnForm" method="POST" action="{{ route('purchasing.returns.store') }}">
        @csrf
        <input type="hidden" name="return_id" :value="returnId">
        <input type="hidden" name="status" :value="formStatus">
        <input type="hidden" name="return_type" :value="returnType">
        <input type="hidden" name="supplier_id" :value="supplierId">
        <input type="hidden" name="purchase_invoice_id" :value="returnType === 'invoice' ? (selectedDocId || '') : ''">
        <input type="hidden" name="purchase_order_id" :value="returnType === 'po' ? (selectedDocId || '') : ''">
        <input type="hidden" name="refund_amount" :value="returnType === 'po' ? poRefundAmount : ''">

        <div class="grid grid-cols-12 gap-5">

            {{-- LEFT COLUMN --}}
            <div class="col-span-8 space-y-4">

                {{-- SECTION 1 --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-2 mb-5">
                        <span class="w-7 h-7 bg-blue-600 text-white rounded-lg flex items-center justify-center text-xs font-black">1</span>
                        <h3 class="font-bold text-gray-700">Pilih Supplier &amp; Dokumen</h3>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mb-4">
                        {{-- Supplier search --}}
                        <div class="col-span-1 relative" @click.outside="showSupplierDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Supplier <span class="text-red-500">*</span></label>
                            <input type="text"
                                x-model="supplierQuery"
                                @input.debounce.300ms="searchSupplier()"
                                @focus="showSupplierDropdown = true; if(supplierQuery.length >= 2) searchSupplier()"
                                placeholder="Cari supplier..."
                                autocomplete="off"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                            <div x-show="showSupplierDropdown && supplierResults.length > 0" x-transition class="absolute bg-white border border-gray-200 w-full mt-1 rounded-xl shadow-lg z-20 max-h-60 overflow-y-auto">
                                <template x-for="s in supplierResults" :key="s.id">
                                    <div @click="selectSupplier(s)"
                                        class="px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-blue-50 hover:text-blue-700 cursor-pointer border-b border-gray-100 last:border-0 transition-colors">
                                        <span x-text="s.name"></span>
                                        <span class="text-[10px] text-gray-400 ml-1" x-text="s.code"></span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Tipe Retur --}}
                        <div class="col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Jenis Retur</label>
                            <select x-model="returnType" @change="onReturnTypeChange()" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                <option value="invoice">📄 Dari Invoice (Barang Rusak)</option>
                                <option value="po">📋 Dari PO (Refund DP)</option>
                            </select>
                        </div>

                        {{-- Document selector --}}
                        <div class="col-span-1 relative" x-show="documents.length > 0" x-transition @click.outside="showDocDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5" x-text="returnType === 'invoice' ? 'Invoice *' : 'Purchase Order *'"></label>
                            <input type="text"
                                x-model="docQuery"
                                @input="showDocDropdown = true"
                                @focus="showDocDropdown = true"
                                placeholder="Cari nomor dokumen..."
                                autocomplete="off"
                                class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
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
                                <div x-show="filteredDocs.length === 0" class="px-4 py-3 text-sm text-gray-500 text-center italic">Tidak ditemukan</div>
                            </div>
                        </div>

                        <div class="col-span-3" x-show="supplierId && documents.length === 0 && !loadingDocs" x-transition>
                            <div class="text-center py-4 bg-amber-50 rounded-xl border border-amber-100">
                                <span class="text-amber-500 font-semibold text-sm" x-text="returnType === 'invoice' ? '⚠️ Tidak ada invoice posted untuk supplier ini.' : '⚠️ Tidak ada PO aktif untuk supplier ini.'"></span>
                            </div>
                        </div>

                        <div class="col-span-3" x-show="loadingDocs" x-transition>
                            <div class="text-blue-500 text-sm py-3 italic">Memuat dokumen...</div>
                        </div>
                    </div>

                    {{-- Document badge --}}
                    <div x-show="selectedDoc" x-transition class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-xl p-4 flex items-start gap-4">
                        <div class="bg-blue-600 text-white rounded-lg p-2 mt-0.5 text-sm font-bold" x-text="returnType === 'invoice' ? 'INV' : 'PO'"></div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="font-black text-blue-800 text-sm" x-text="selectedDoc?.number"></span>
                                <span class="bg-green-100 text-green-700 text-[10px] font-black px-2 py-0.5 rounded-full uppercase" x-text="selectedDoc?.status"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-xs text-gray-500">
                                <div>Tanggal: <span class="text-gray-700 font-semibold" x-text="selectedDoc?.date"></span></div>
                                <div>Total: <span class="text-gray-700 font-semibold" x-text="`Rp ${formatNumber(selectedDoc?.grand_total)}`"></span></div>
                                <div x-show="returnType === 'invoice'">Item: <span class="text-gray-700 font-semibold" x-text="`${selectedDoc?.items?.length ?? 0} produk`"></span></div>
                            </div>
                        </div>
                    </div>

                    {{-- Return date --}}
                    <div class="mt-4" x-show="selectedDoc" x-transition>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Tanggal Retur <span class="text-red-500">*</span></label>
                        <input type="date" name="return_date" x-model="returnDate"
                               class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                               :max="today">
                    </div>
                </div>

                {{-- SECTION 2: ITEMS (invoice) atau REFUND DP (po) --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6" x-show="selectedDoc" x-transition>

                    {{-- INVOICE: items list --}}
                    <template x-if="returnType === 'invoice'">
                        <div>
                            <div class="flex items-center justify-between mb-5">
                                <div class="flex items-center gap-2">
                                    <span class="w-7 h-7 bg-purple-600 text-white rounded-lg flex items-center justify-center text-xs font-black">2</span>
                                    <h3 class="font-bold text-gray-700">Item yang Diretur</h3>
                                </div>
                                <div class="text-xs text-gray-400 font-medium">
                                    <span x-text="items.filter(i => parseFloat(i.qty_return) > 0).length"></span> item dipilih
                                </div>
                            </div>

                            <div class="text-[10px] text-gray-500 mb-3 italic flex items-center gap-2">
                                <span class="bg-blue-50 px-2 py-0.5 rounded">i</span>
                                Refund per unit = <b>HPP</b> (harga supplier − diskon + landed cost capitalized). Beban direct (non-capitalized) tidak ikut di-refund.
                            </div>

                            <div class="overflow-hidden rounded-xl border border-gray-100">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-100">
                                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Produk</th>
                                            <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-24">Qty Inv</th>
                                            <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-24">Sisa</th>
                                            <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-28">Qty Retur</th>
                                            <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-24">Refund/Pcs</th>
                                            <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-32">Nilai Retur</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50">
                                        <template x-for="(item, index) in items" :key="item.id">
                                            <tr :class="{
                                                    'bg-blue-50/40': parseFloat(item.qty_return) > 0,
                                                    'opacity-50':    parseFloat(item.qty_return) === 0
                                                }">
                                                <td class="px-4 py-3">
                                                    <input type="hidden" :name="`items[${index}][purchase_invoice_item_id]`" :value="item.id">
                                                    <div class="font-semibold text-gray-800" x-text="item.name"></div>
                                                </td>
                                                <td class="px-4 py-3 text-center font-bold text-gray-700" x-text="item.qty"></td>
                                                <td class="px-4 py-3 text-center font-bold text-gray-700" x-text="item.qty_remaining"></td>
                                                <td class="px-4 py-3 text-center">
                                                    <input type="number"
                                                        :name="`items[${index}][qty]`"
                                                        x-model="item.qty_return"
                                                        :max="item.qty_remaining"
                                                        min="0" step="0.01"
                                                        @input="onQtyChange(index)"
                                                        @blur="validateQty(index)"
                                                        class="w-20 border-2 text-center rounded-lg px-2 py-1.5 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-400 transition"
                                                        :class="{
                                                            'border-red-400 bg-red-50': item.qty_error,
                                                            'border-blue-300 bg-blue-50': parseFloat(item.qty_return) > 0 && !item.qty_error,
                                                            'border-gray-200': !parseFloat(item.qty_return) && !item.qty_error
                                                        }">
                                                    <div class="text-[9px] text-red-500 font-bold mt-0.5" x-show="item.qty_error" x-text="item.qty_error"></div>
                                                </td>
                                                <td class="px-4 py-3 text-right text-xs text-gray-500" x-text="`Rp ${formatNumber(item.refund_per_unit)}`"></td>
                                                <td class="px-4 py-3 text-right">
                                                    <div class="font-black text-gray-700" x-text="parseFloat(item.qty_return) > 0 ? `Rp ${formatNumber(getLineValue(item))}` : '—'"></div>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="items.length === 0">
                                            <td colspan="6" class="px-4 py-8 text-center text-gray-300 font-medium text-sm">Pilih invoice terlebih dahulu</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    {{-- PO: refund DP input --}}
                    <template x-if="returnType === 'po'">
                        <div>
                            <div class="flex items-center justify-between mb-5">
                                <div class="flex items-center gap-2">
                                    <span class="w-7 h-7 bg-purple-600 text-white rounded-lg flex items-center justify-center text-xs font-black">2</span>
                                    <h3 class="font-bold text-gray-700">Refund DP</h3>
                                </div>
                            </div>

                            <div class="bg-purple-50 border border-purple-100 rounded-xl p-4 mb-4">
                                <div class="text-[10px] font-black text-purple-700 uppercase tracking-widest mb-1">Saldo Uang Muka Supplier (1107)</div>
                                <div class="text-2xl font-black text-purple-900">Rp <span x-text="formatNumber(dpBalance)"></span></div>
                                <div class="text-[10px] text-purple-600 italic mt-1">Refund tidak boleh melebihi saldo ini.</div>
                            </div>

                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Nominal Refund DP (Rp) <span class="text-red-500">*</span></label>
                            <input type="number"
                                x-model="poRefundAmount"
                                :max="dpBalance"
                                min="0" step="0.01"
                                placeholder="0"
                                class="w-full border-2 rounded-xl px-3.5 py-3 text-2xl font-black focus:outline-none focus:ring-2 focus:ring-purple-400 transition"
                                :class="{
                                    'border-red-400 bg-red-50': poRefundError,
                                    'border-purple-300 bg-purple-50': parseFloat(poRefundAmount) > 0 && !poRefundError,
                                    'border-gray-200': !parseFloat(poRefundAmount)
                                }">
                            <div class="text-[10px] text-red-500 font-bold mt-1" x-show="poRefundError" x-text="poRefundError"></div>

                            <div class="mt-3 flex gap-2">
                                <button type="button" @click="poRefundAmount = dpBalance"
                                    class="flex-1 bg-white hover:bg-purple-50 text-purple-700 border border-purple-200 px-3 py-2 rounded-lg text-xs font-black uppercase tracking-wide transition">
                                    Pakai Semua Saldo DP
                                </button>
                                <button type="button" x-show="selectedDoc" @click="poRefundAmount = Math.min(dpBalance, parseFloat(selectedDoc?.grand_total || 0))"
                                    class="flex-1 bg-white hover:bg-purple-50 text-purple-700 border border-purple-200 px-3 py-2 rounded-lg text-xs font-black uppercase tracking-wide transition">
                                    Sebesar Total PO
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Notes --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6" x-show="selectedDoc" x-transition>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Catatan</label>
                    <textarea name="notes" x-model="notes" rows="2" class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Catatan retur..."></textarea>
                </div>

            </div>

            {{-- RIGHT COLUMN --}}
            <div class="col-span-4 space-y-4">

                {{-- Ringkasan + Preview Jurnal --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" x-show="selectedDoc" x-transition>
                    <div class="p-5">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="w-7 h-7 bg-orange-500 text-white rounded-lg flex items-center justify-center text-xs font-black">3</span>
                            <h3 class="font-bold text-gray-700">Ringkasan Retur</h3>
                        </div>

                        <div class="space-y-3">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-500 font-medium">Total Refund</span>
                                <span class="font-bold text-gray-700">Rp <span x-text="formatNumber(summary.total)"></span></span>
                            </div>
                            <div class="border-t border-gray-100 pt-3 flex justify-between items-center">
                                <span class="font-black text-gray-800">Saldo Piutang naik</span>
                                <span class="font-black text-purple-600 text-lg">Rp <span x-text="formatNumber(summary.total)"></span></span>
                            </div>
                        </div>

                        <div class="mt-4 border-t border-gray-100 pt-4">
                            <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3">Preview Jurnal</h4>
                            <div class="bg-white border border-gray-200 rounded-xl p-4 text-[11px] font-mono leading-relaxed">
                                <div x-show="summary.total === 0" class="text-gray-300 italic text-center text-[10px] py-1">Menunggu input...</div>

                                <template x-for="(line, idx) in journalPreview" :key="idx">
                                    <div class="flex justify-between" :class="line.indent ? 'pl-4' : ''">
                                        <span :class="line.cssClass" x-text="line.label"></span>
                                        <span class="font-black" x-text="formatNumber(line.amount)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Warnings --}}
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5" x-show="selectedDoc" x-transition>
                    <div class="font-bold text-amber-700 text-sm mb-2">EFEK RETUR:</div>
                    <ul class="space-y-2 text-xs text-amber-600 font-medium">
                        <template x-if="returnType === 'invoice'">
                            <li class="flex items-start gap-2">
                                <span class="bg-amber-200 text-amber-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">1</span>
                                <span>Stok &amp; layer FIFO berkurang sebesar HPP barang. Beban direct (non-capitalized) tetap menjadi expense perusahaan.</span>
                            </li>
                        </template>
                        <template x-if="returnType === 'po'">
                            <li class="flex items-start gap-2">
                                <span class="bg-amber-200 text-amber-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">1</span>
                                <span>Saldo Uang Muka Supplier (1107) berkurang FIFO; pindah ke Piutang Lebih Bayar Supplier (1108).</span>
                            </li>
                        </template>
                        <li class="flex items-start gap-2">
                            <span class="bg-amber-200 text-amber-700 w-4 h-4 rounded-full flex items-center justify-center text-[10px] shrink-0 font-black">2</span>
                            <span>Saldo Piutang Lebih Bayar Supplier bertambah → bisa dipakai via tombol "Pakai Saldo" di Payment, atau diterima sebagai cash di Kas/Bank.</span>
                        </li>
                        <li class="flex items-start gap-2 pt-1 border-t border-amber-200/50">
                            <span class="text-red-500 font-black">✖</span>
                            <span class="text-red-600 font-black uppercase tracking-tight">Data permanen setelah POST.</span>
                        </li>
                    </ul>
                </div>

                {{-- Action buttons --}}
                <div class="flex flex-col gap-3" x-show="selectedDoc" x-transition>
                    <div class="flex gap-2">
                        <button type="button" @click="saveDraft()" :disabled="!canSubmit()"
                                class="flex-1 py-3.5 rounded-xl font-bold text-sm transition-all border border-gray-200"
                                :class="canSubmit() ? 'bg-white text-gray-700 hover:bg-gray-50' : 'bg-gray-50 text-gray-300 cursor-not-allowed'">
                            <span x-text="returnId ? '💾 Update Draft' : '💾 Save Draft'"></span>
                        </button>
                        <button type="button" @click="confirmAndSubmit()" :disabled="!canSubmit()"
                                class="flex-[1.5] py-3.5 rounded-xl font-black text-sm transition-all shadow-lg"
                                :class="canSubmit() ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-blue-200 active:scale-95' : 'bg-gray-100 text-gray-400 cursor-not-allowed'">
                            POST RETUR
                        </button>
                    </div>
                    <a href="{{ route('purchasing.returns.index') }}"
                       class="w-full py-3 rounded-xl font-semibold text-sm text-gray-400 hover:text-gray-600 transition-all flex items-center justify-center">
                        Batal &amp; Kembali
                    </a>
                </div>
            </div>
        </div>
    </form>

    {{-- Confirm modal --}}
    <div x-show="showConfirm" x-transition class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
        <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" @click="showConfirm = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 z-10">
            <div class="text-center mb-5">
                <h2 class="text-lg font-black text-gray-800">Konfirmasi Retur</h2>
                <p class="text-gray-500 text-sm mt-1">Tindakan ini tidak dapat dibatalkan setelah post.</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-4 mb-5 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">Supplier</span><span class="font-bold text-gray-800" x-text="supplierName"></span></div>
                <div class="flex justify-between"><span class="text-gray-500" x-text="returnType === 'invoice' ? 'Invoice' : 'PO'"></span><span class="font-bold text-gray-800" x-text="selectedDoc?.number"></span></div>
                <div class="flex justify-between"><span class="text-gray-500">Total Refund</span><span class="font-black text-purple-600">Rp <span x-text="formatNumber(summary.total)"></span></span></div>
            </div>
            <div class="flex gap-3">
                <button type="button" @click="showConfirm = false" class="flex-1 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">Batal</button>
                <button type="button" @click="doSubmit()" class="flex-1 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-black hover:bg-blue-700 transition active:scale-95 shadow-lg shadow-blue-200">Ya, POST</button>
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
        returnId: initialData?.id || null,
        formStatus: 'posted',
        supplierId: initialData?.supplier_id || null,
        supplierName: initialData?.supplier?.name || '',
        supplierQuery: initialData?.supplier?.name || '',
        supplierResults: [],
        showSupplierDropdown: false,

        returnType: initialData?.return_type || 'invoice',
        documents: [],
        selectedDocId: initialData?.purchase_invoice_id || initialData?.purchase_order_id || null,
        selectedDoc: null,
        docQuery: initialData?.purchase_invoice?.invoice_number || initialData?.purchase_order?.po_number || '',
        showDocDropdown: false,
        loadingDocs: false,

        items: [],
        poRefundAmount: initialData?.return_type === 'po' ? parseFloat(initialData?.refund_amount || 0) : 0,
        poRefundError: null,
        dpBalance: 0,

        returnDate: initialData?.return_date ? initialData.return_date.split('T')[0] : '{{ now()->format("Y-m-d") }}',
        today: '{{ now()->format("Y-m-d") }}',
        notes: initialData?.notes || '',

        summary: { total: 0 },
        journalPreview: [],
        showConfirm: false,

        async init() {
            this.$watch('items', () => this.recompute(), { deep: true });
            this.$watch('poRefundAmount', () => this.recompute());

            if (this.returnId && initialData) {
                this.loadingDocs = true;
                try {
                    await this.loadDocuments();
                    this.selectedDoc = this.documents.find(d => d.id == this.selectedDocId);
                    if (this.selectedDoc && this.returnType === 'invoice') {
                        this.items = this.selectedDoc.items.map(docItem => {
                            const ri = (initialData.items || []).find(x => x.purchase_invoice_item_id == docItem.id);
                            return {
                                id: docItem.id,
                                product_id: docItem.product_id,
                                name: docItem.name,
                                qty: docItem.qty,
                                qty_remaining: docItem.qty_remaining + (ri ? parseFloat(ri.qty) : 0), // sisa + balik dulu yang sudah masuk draft
                                qty_return: ri ? parseFloat(ri.qty) : 0,
                                refund_per_unit: docItem.refund_per_unit,
                                qty_error: null,
                            };
                        });
                    }
                    if (this.returnType === 'po') {
                        await this.refreshDpBalance();
                    }
                    this.recompute();
                } finally {
                    this.loadingDocs = false;
                }
            }
        },

        async searchSupplier() {
            if (this.supplierQuery.length < 1) { this.supplierResults = []; return; }
            try {
                const res = await fetch(`/erp/purchasing/api/suppliers/search?q=${encodeURIComponent(this.supplierQuery)}`);
                this.supplierResults = await res.json();
            } catch (e) { console.error(e); }
        },

        async selectSupplier(s) {
            this.supplierQuery = s.name;
            this.supplierName = s.name;
            this.supplierId = s.id;
            this.showSupplierDropdown = false;
            this.docQuery = '';
            this.selectedDoc = null;
            this.selectedDocId = null;
            this.items = [];
            this.poRefundAmount = 0;
            await this.loadDocuments();
            if (this.returnType === 'po') await this.refreshDpBalance();
        },

        async loadDocuments() {
            if (!this.supplierId) { this.documents = []; return; }
            this.loadingDocs = true;
            try {
                const url = this.returnType === 'invoice'
                    ? `{{ route('purchasing.api.returns.invoices') }}?supplier_id=${this.supplierId}`
                    : `{{ route('purchasing.api.returns.orders') }}?supplier_id=${this.supplierId}`;
                const res = await fetch(url);
                this.documents = await res.json();
            } finally {
                this.loadingDocs = false;
            }
        },

        async refreshDpBalance() {
            if (!this.supplierId) { this.dpBalance = 0; return; }
            const res = await fetch(`{{ route('purchasing.api.returns.dp-balance') }}?supplier_id=${this.supplierId}`);
            const data = await res.json();
            this.dpBalance = parseFloat(data.balance || 0);
        },

        get filteredDocs() {
            if (!this.docQuery) return this.documents;
            return this.documents.filter(d => d.number.toLowerCase().includes(this.docQuery.toLowerCase()));
        },

        async onReturnTypeChange() {
            this.docQuery = '';
            this.selectedDoc = null;
            this.selectedDocId = null;
            this.items = [];
            this.poRefundAmount = 0;
            await this.loadDocuments();
            if (this.returnType === 'po') await this.refreshDpBalance();
            this.recompute();
        },

        selectDoc(doc) {
            this.docQuery = doc.number;
            this.selectedDocId = doc.id;
            this.selectedDoc = doc;
            this.showDocDropdown = false;
            if (this.returnType === 'invoice') {
                this.items = (doc.items || []).map(it => ({
                    id: it.id,
                    product_id: it.product_id,
                    name: it.name,
                    qty: it.qty,
                    qty_remaining: it.qty_remaining,
                    qty_return: 0,
                    refund_per_unit: it.refund_per_unit,
                    qty_error: null,
                }));
            }
            this.recompute();
        },

        validateQty(index) {
            const item = this.items[index];
            const qty = parseFloat(item.qty_return) || 0;
            if (qty < 0) { item.qty_return = 0; item.qty_error = null; return; }
            if (qty > item.qty_remaining) { item.qty_error = `Maks ${item.qty_remaining}`; item.qty_return = item.qty_remaining; }
            else item.qty_error = null;
        },

        onQtyChange(index) {
            this.validateQty(index);
            this.recompute();
        },

        getLineValue(item) {
            const q = parseFloat(item.qty_return) || 0;
            return Math.round(q * parseFloat(item.refund_per_unit || 0) * 100) / 100;
        },

        recompute() {
            let total = 0;
            if (this.returnType === 'invoice') {
                this.items.forEach(it => {
                    const q = parseFloat(it.qty_return) || 0;
                    if (q > 0) total += this.getLineValue(it);
                });
            } else {
                const refund = parseFloat(this.poRefundAmount || 0);
                this.poRefundError = refund > this.dpBalance + 0.01 ? `Melebihi saldo DP (Rp ${this.formatNumber(this.dpBalance)})` : null;
                total = refund > 0 ? refund : 0;
            }
            this.summary = { total: Math.round(total * 100) / 100 };
            this.journalPreview = this.buildJournalPreview();
        },

        buildJournalPreview() {
            const total = this.summary.total;
            if (total <= 0) return [];
            if (this.returnType === 'po') {
                return [
                    { label: 'Dr. 1108 Piutang Lebih Bayar Supplier', amount: total, cssClass: 'text-purple-700 font-bold', indent: false },
                    { label: 'Cr. 1107 Uang Muka Supplier', amount: total, cssClass: 'text-gray-500 italic', indent: true },
                ];
            }
            // Invoice
            return [
                { label: 'Dr. 2101 / 1108 (AP / Piutang Lebih Bayar)', amount: total, cssClass: 'text-purple-700 font-bold', indent: false },
                { label: 'Cr. 1130 Persediaan', amount: total, cssClass: 'text-gray-500 italic', indent: true },
            ];
        },

        canSubmit() {
            if (!this.supplierId || !this.selectedDoc || !this.returnDate) return false;
            if (this.returnType === 'invoice') {
                const hasItem = this.items.some(i => parseFloat(i.qty_return) > 0);
                const hasErr = this.items.some(i => i.qty_error);
                return hasItem && !hasErr && this.summary.total > 0;
            }
            return this.summary.total > 0 && !this.poRefundError;
        },

        saveDraft() {
            if (!this.canSubmit()) return;
            this.formStatus = 'draft';
            this.$nextTick(() => document.getElementById('returnForm').submit());
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

        formatNumber(n) {
            if (!n && n !== 0) return '0';
            return Math.round(n).toLocaleString('id-ID');
        },
    };
}
</script>
@endpush
