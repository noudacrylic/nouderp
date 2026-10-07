@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="returForm({{ isset($return) ? $return->load('items', 'customer')->toJson() : 'null' }}, {{ json_encode($prefill ?? null) }})" x-init="init()">

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
            <p class="text-xs text-gray-500 mt-0.5">
                1 · Pilih faktur &amp; kasusnya → 2 · Item yang diretur → 3 · Jurnal (HPP · Pembalikan · Penyelesaian)
            </p>
        </div>
        <a href="{{ route('sales.returns.index') }}"
           class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 hover:bg-gray-50 bg-white px-4 py-2 rounded-xl text-sm font-semibold transition-all">
            ← Kembali
        </a>
    </div>

    {{-- ALERT: Error --}}

    @if(!empty($pindahFaktur['invoice']))
        <form id="pindahFakturForm" method="POST" action="{{ route('sales.returns.pindah-faktur', $return->id) }}" class="hidden">
            @csrf
        </form>
    @endif

    <form id="returnForm" method="POST" action="{{ route('sales.returns.store') }}">
        @csrf
        <input type="hidden" name="return_id" :value="returnId">
        <input type="hidden" name="status" :value="formStatus">
        {{-- Diisi tombol Ajukan Banding / Kembalikan: draft disimpan DULU, baru tahapnya pindah. --}}
        <input type="hidden" name="pindah_tahap" :value="pindahTahap">

        {{-- Submit payload: 1 alokasi = 1 items[]; satu produk bisa jadi beberapa baris (per kondisi).
             Bundle: 1 baris + component_conditions per komponen. --}}
        <template x-for="(a, i) in flatItems()" :key="i">
            <div>
                <input type="hidden" :name="`items[${i}][invoice_item_id]`" :value="a.invoice_item_id">
                <input type="hidden" :name="`items[${i}][qty]`"             :value="a.qty">
                <input type="hidden" :name="`items[${i}][condition]`"       :value="a.condition">
                <template x-for="(cond, pid) in (a.component_conditions || {})" :key="pid">
                    <input type="hidden" :name="`items[${i}][component_conditions][${pid}]`" :value="cond">
                </template>
            </div>
        </template>

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

                        {{-- Sumber dokumen TIDAK lagi ditanyakan.

                             Dulu CS memilih "Dari Faktur" atau "Dari Sales Order", dan pilihan
                             itu diam-diam mengubah jurnal (membalik Penjualan vs Uang Muka) —
                             keputusan akuntansi yang disamarkan jadi pertanyaan pemilihan
                             dokumen. Sejak faktur terbit saat pengiriman, SEMUA barang yang
                             pernah keluar pasti punya faktur, jadi pilihan itu tak punya alasan
                             hidup lagi: pesanan yang belum dikirim bukan urusan retur, itu
                             pembatalan. Penanda ini hanya muncul saat membuka retur LAMA yang
                             terlanjur dibuat atas SO, supaya dokumennya tetap bisa dibaca. --}}
                        <div class="col-span-1" x-show="returnType === 'so'" x-cloak>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">Sumber Dokumen</label>
                            <div class="w-full border border-amber-200 bg-amber-50 rounded-xl px-3.5 py-2.5 text-sm text-amber-700">
                                📋 Dari Sales Order <span class="text-[11px]">(dokumen lama)</span>
                            </div>
                            {{-- SO-nya sudah berfaktur → retur ini seharusnya atas faktur. Tombolnya
                                 memakai form terpisah (form tak boleh bersarang); isian yang belum
                                 disimpan hilang, jadi simpan draft dulu bila ada perubahan. --}}
                            @if(!empty($pindahFaktur['invoice']))
                                <button type="submit" form="pindahFakturForm"
                                    onclick="return confirm('Pindahkan retur ini ke faktur {{ $pindahFaktur['invoice']->invoice_number }}? Tanggal retur tetap. Perubahan yang belum disimpan akan hilang.')"
                                    class="mt-1.5 w-full border border-blue-300 text-blue-700 hover:bg-blue-50 bg-white rounded-lg px-2.5 py-1 text-xs font-semibold transition-colors">
                                    Pindahkan ke Faktur {{ $pindahFaktur['invoice']->invoice_number }}
                                </button>
                            @elseif(!empty($pindahFaktur['alasan']))
                                <p class="mt-1 text-[11px] text-gray-500">{{ $pindahFaktur['alasan'] }}</p>
                            @endif
                        </div>

                        {{-- Document Selector Search --}}
                        <div class="col-span-1 relative" x-show="documents.length > 0 || docQuery" x-transition @click.outside="showDocDropdown = false">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5" x-text="returnType === 'invoice' ? 'Faktur *' : 'Sales Order *'"></label>
                            
                            <input type="text"
                                x-model="docQuery"
                                @input="showDocDropdown = true; searchDocuments()"
                                @focus="showDocDropdown = true; ensureDocumentsLoaded()"
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
                                <div x-show="filteredDocs.length === 0" class="px-4 py-3 text-sm text-gray-500 text-center italic"
                                    x-text="searchingDocs ? 'Mencari…' : 'Tidak ditemukan'">
                                </div>
                                <div x-show="!docQuery && documents.length >= 50" class="px-4 py-2 text-[11px] text-gray-400 text-center bg-gray-50">
                                    Menampilkan 50 terbaru — ketik nomor untuk mencari yang lebih lama
                                </div>
                            </div>
                        </div>

                        {{-- No documents message --}}
                        <div class="col-span-3" x-show="customerId && documents.length === 0 && !loadingDocs && !docQuery" x-transition>
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
                                {{-- Salin nomor pesanan inti (tanpa prefix SP-/TT- & store-id) untuk dicocokkan di Seller Center --}}
                                <button type="button"
                                        x-show="nomorPesananInti(selectedDoc?.number)"
                                        @click="salinNomorPesanan()"
                                        :title="'Salin nomor pesanan: ' + nomorPesananInti(selectedDoc?.number)"
                                        class="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-md border transition"
                                        :class="nomorTersalin ? 'border-green-300 bg-green-50 text-green-700' : 'border-blue-200 bg-white text-blue-600 hover:bg-blue-50'">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    <span x-text="nomorTersalin ? 'Tersalin' : nomorPesananInti(selectedDoc?.number)"></span>
                                </button>
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

                    {{-- Definisi kasus retur. Selama Jenis Retur kosong, retur menunggu di
                         tab "Retur Baru" dan tidak bisa diselesaikan. Jenis + Hasil Banding
                         + kondisi barang menentukan isi bawaan jurnal di langkah 3. --}}
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4" x-show="selectedDoc" x-transition>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">
                                Jenis Retur <span class="text-red-500">*</span>
                            </label>
                            <select name="return_type" x-model="caseType"
                                    @change="gantiJenis()"
                                    class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">— Belum ditentukan —</option>
                                @foreach(\App\Modules\Sales\Models\SalesReturn::RETURN_TYPES as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Kasus = rincian jenis retur, sama dengan daftar Panduan Jurnal di kanan.
                             Kasus menentukan penjualan dibalik atau tidak (hasil banding tersirat di
                             dalamnya) dan kondisi barang bawaannya. --}}
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">
                                Kasus <span class="text-red-500" x-show="caseType">*</span>
                            </label>
                            <select name="return_case" x-model="returnCase" @change="gantiKasus()" :disabled="!caseType"
                                    class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-400">
                                <option value="" x-text="caseType ? '— Pilih kasus —' : '— Pilih jenis retur dulu —'"></option>
                                <template x-for="(label, kode) in (KASUS[caseType] || {})" :key="kode">
                                    <option :value="kode" x-text="label" :selected="kode === returnCase"></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">
                                Nomor Retur Marketplace
                            </label>
                            <input type="text" name="external_return_number" x-model="externalReturnNo"
                                   placeholder="Nomor yang tertempel di paket retur"
                                   class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        {{-- Apa yang dituntut tiap jenis, dikatakan di tempat memilihnya. --}}
                        <p class="md:col-span-3 -mt-2 text-[11px] leading-relaxed text-gray-500"
                           x-show="caseType" x-cloak x-text="penjelasanKasus()"></p>

                        <div class="md:col-span-3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-widest mb-1.5">
                                Catatan Penanganan
                            </label>
                            <textarea name="notes" x-model="caseNotes" rows="3"
                                      placeholder="Riwayat banding, video packing yang dikirim, tanggapan marketplace…"
                                      class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
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
                            <span x-text="items.filter(i => itemTotalQty(i) > 0).length"></span> item dipilih
                        </div>
                    </div>

                    {{-- Legend --}}
                    <div class="flex items-center gap-4 mb-4 text-xs font-semibold text-gray-400 flex-wrap">
                        <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-green-400"></span> Utuh → Stok kembali + HPP reverse</div>
                        <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-yellow-400"></span> Perbaikan → Persediaan Perbaikan + HPP reverse</div>
                        <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-red-400"></span> Rusak → Beban kerugian, tidak masuk stok</div>
                        <div class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-gray-500"></span> Tidak Kembali → jurnalnya sama dengan Rusak</div>
                        <div class="w-full text-[11px] text-gray-400 font-medium">Kondisi hanya menentukan jurnal HPP. Penjualan dibalik atau tidak ditentukan Jenis Retur &amp; Hasil Banding.</div>
                        <div class="flex items-center gap-1.5 text-blue-400">Butuh campur kondisi? Klik <span class="font-bold">"Pisah kondisi"</span> pada baris produk.</div>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-gray-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-100">
                                    <th class="px-4 py-3 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Produk</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-24" x-text="returnType === 'invoice' ? 'Qty Faktur' : 'Qty Order'"></th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-28">Qty Retur</th>
                                    <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Kondisi Barang<br><span class="normal-case font-semibold tracking-normal text-gray-300">yang kembali ke penjual</span></th>
                                    <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Nilai Retur</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <template x-for="(item, index) in items" :key="item.id">
                                    <tr class="group transition-colors align-top"
                                        :class="{
                                            'bg-green-50/50': !item.split && item.condition === 'good' && parseFloat(item.qty_return) > 0,
                                            'bg-red-50/50':   !item.split && item.condition === 'damaged' && parseFloat(item.qty_return) > 0,
                                            'bg-yellow-50/40': item.split && itemTotalQty(item) > 0,
                                            'opacity-50':     itemTotalQty(item) === 0
                                        }">
                                        {{-- Product --}}
                                        <td class="px-4 py-3">
                                            <div class="font-semibold text-gray-800">
                                                <span x-text="item.name"></span>
                                                <span x-show="item.is_bundle" class="ml-1 text-[9px] bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded font-bold align-middle">BUNDLE</span>
                                            </div>
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
                                            {{-- Mode simpel: 1 input qty --}}
                                            <template x-if="!item.split">
                                                <div class="relative inline-block">
                                                    <input type="number"
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
                                            </template>
                                            {{-- Mode pisah: total (Σ) baca-saja --}}
                                            <template x-if="item.split">
                                                <div>
                                                    <div class="text-sm font-black" :class="item.qty_error ? 'text-red-500' : 'text-gray-700'">
                                                        <span x-text="itemTotalQty(item)"></span> <span class="text-[9px] text-gray-400 font-semibold">/ <span x-text="item.qty"></span></span>
                                                    </div>
                                                    <div class="text-[9px] text-red-500 font-bold mt-0.5" x-show="item.qty_error" x-text="item.qty_error"></div>
                                                </div>
                                            </template>
                                        </td>

                                        {{-- Kondisi --}}
                                        <td class="px-4 py-3 text-center">
                                            {{-- Bundle: kondisi PER KOMPONEN --}}
                                            <template x-if="item.is_bundle">
                                                <div class="flex flex-col gap-1.5 items-stretch min-w-[190px]">
                                                    <span class="text-[9px] text-gray-400 font-bold uppercase tracking-wide text-left">Kondisi per komponen</span>
                                                    <template x-for="c in item.components" :key="c.product_id">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="text-[11px] font-semibold text-gray-600 truncate" :title="c.name" x-text="c.name"></span>
                                                            <select x-model="item.component_conditions[c.product_id]" @change="calculateSummary()"
                                                                    class="border rounded-lg px-1.5 py-1 text-[11px] font-bold focus:outline-none focus:ring-2 transition flex-shrink-0"
                                                                    :class="{
                                                                        'border-green-300 text-green-700 bg-green-50':  item.component_conditions[c.product_id] === 'good',
                                                                        'border-yellow-300 text-yellow-700 bg-yellow-50': item.component_conditions[c.product_id] === 'repair',
                                                                        'border-red-300   text-red-700   bg-red-50':    item.component_conditions[c.product_id] === 'damaged'
                                                                    }">
                                                                <option value="good">🟢 Utuh</option>
                                                                <option value="repair">🟡 Perbaikan</option>
                                                                <option value="damaged">🔴 Rusak</option>
                                                                <option value="tidak_kembali">⚪ Tidak Kembali</option>
                                                            </select>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>
                                            {{-- Mode simpel (non-bundle): dropdown + tombol pisah --}}
                                            <template x-if="!item.split && !item.is_bundle">
                                                <div class="flex flex-col items-center gap-1">
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
                                                        <option value="damaged">🔴 Rusak</option>
                                                        <option value="tidak_kembali">⚪ Tidak Kembali</option>
                                                    </select>
                                                    <button type="button" @click="enableSplit(index)"
                                                            class="text-[9px] text-blue-500 hover:text-blue-700 hover:underline font-semibold">
                                                        + Pisah kondisi
                                                    </button>
                                                </div>
                                            </template>
                                            {{-- Mode pisah: 3 input qty per kondisi --}}
                                            <template x-if="item.split">
                                                <div class="flex flex-col gap-1.5 items-stretch min-w-[150px]">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[11px] font-bold text-green-700">🟢 Utuh</span>
                                                        <input type="number" x-model="item.splits.good" @input="onSplitChange(index)" min="0" step="0.01"
                                                               class="w-16 border-2 border-green-200 text-center rounded-lg px-1.5 py-1 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-green-300">
                                                    </div>
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[11px] font-bold text-yellow-700">🟡 Perbaikan</span>
                                                        <input type="number" x-model="item.splits.repair" @input="onSplitChange(index)" min="0" step="0.01"
                                                               class="w-16 border-2 border-yellow-200 text-center rounded-lg px-1.5 py-1 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-yellow-300">
                                                    </div>
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[11px] font-bold text-red-700">🔴 Rusak</span>
                                                        <input type="number" x-model="item.splits.damaged" @input="onSplitChange(index)" min="0" step="0.01"
                                                               class="w-16 border-2 border-red-200 text-center rounded-lg px-1.5 py-1 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-red-300">
                                                    </div>
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[11px] font-bold text-gray-600">⚪ Tidak Kembali</span>
                                                        <input type="number" x-model="item.splits.tidak_kembali" @input="onSplitChange(index)" min="0" step="0.01"
                                                               class="w-16 border-2 border-gray-200 text-center rounded-lg px-1.5 py-1 text-xs font-bold focus:outline-none focus:ring-2 focus:ring-gray-300">
                                                    </div>
                                                    <button type="button" @click="disableSplit(index)"
                                                            class="text-[9px] text-gray-400 hover:text-gray-600 hover:underline font-semibold self-end">
                                                        × batal pisah
                                                    </button>
                                                </div>
                                            </template>
                                        </td>

                                        {{-- Nilai Retur --}}
                                        <td class="px-4 py-3 text-right">
                                            <div class="font-black text-gray-700" x-text="itemTotalQty(item) > 0 ? `Rp ${formatNumber(getLineValue(item))}` : '—'"></div>
                                            <div class="text-[9px] text-gray-400 mt-0.5" x-show="itemTotalQty(item) > 0">
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

                {{-- ═══ SECTION 3: JURNAL (tiga blok) ═══
                     a. HPP          — dari kondisi barang, TIDAK bisa diubah (cermin kartu stok).
                     b. Pembalikan   — penjualan yang batal & titipan pembeli dikembalikan.
                     c. Penyelesaian — uang pesanan berakhir di mana (dompet, admin, pajak).
                     Isi b & c bawaan dari SalesReturnService::jurnalBawaan() dan boleh diubah.
                     Draft hanya menyimpan blok yang BENAR-BENAR diubah; blok yang tak disentuh
                     ikut bergerak saat kondisi barang dicek belakangan. --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6" x-show="selectedDoc && returnType === 'invoice'" x-cloak>
                    <input type="hidden" name="tiga_blok" value="1">

                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <span class="w-7 h-7 bg-orange-500 text-white rounded-lg flex items-center justify-center text-xs font-black">3</span>
                            <h3 class="font-bold text-gray-700">Jurnal</h3>
                        </div>
                        <span class="text-[11px] text-gray-400" x-show="memuatBawaan" x-cloak>Menghitung bawaan…</span>
                    </div>

                    {{-- a. HPP --}}
                    <div class="mb-5">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-xs font-black text-gray-600 uppercase tracking-widest">a. Jurnal HPP</span>
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 font-semibold">🔒 mengikuti kondisi barang</span>
                        </div>
                        <div class="rounded-xl border border-gray-100 bg-gray-50/60 px-4 py-3 text-xs font-mono space-y-2">
                            <template x-for="g in journalPreview.conditions" :key="g.condition">
                                <div class="space-y-0.5">
                                    <div class="flex justify-between"><span class="font-bold" :class="g.debitClass" x-text="g.debitLabel"></span><span class="font-black text-gray-800" x-text="formatNumber(g.amount)"></span></div>
                                    <div class="flex justify-between pl-4"><span class="text-gray-500 italic" x-text="g.creditLabel"></span><span class="font-black text-gray-600" x-text="formatNumber(g.amount)"></span></div>
                                </div>
                            </template>
                            <div x-show="journalPreview.conditions.length === 0" class="text-gray-400 italic font-sans">
                                Belum ada barang yang diretur.
                            </div>
                        </div>
                    </div>

                    {{-- b & c. Blok yang bisa diubah --}}
                    <template x-for="kunci in ['pembalikan', 'penyelesaian']" :key="kunci">
                        <div class="mb-5" x-show="blok[kunci].rows !== null">
                            <input type="hidden" :name="`jurnal_ada[${kunci}]`" value="1" :disabled="!kirimBlok(kunci)">

                            <div class="flex items-center justify-between gap-2 mb-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-black text-gray-600 uppercase tracking-widest"
                                          x-text="kunci === 'pembalikan' ? 'b. Pembalikan Jurnal Faktur' : 'c. Penyelesaian Pesanan'"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold"
                                          :class="blok[kunci].diubah ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-50 text-emerald-700'"
                                          x-text="blok[kunci].diubah ? '✏️ diubah' : 'bawaan sistem'"></span>
                                </div>
                                <button type="button" @click="isiUlangBlok(kunci)"
                                        class="text-[11px] font-semibold text-gray-500 hover:text-indigo-700">↺ Isi ulang dari bawaan</button>
                            </div>
                            <p class="text-[11px] text-gray-400 mb-3"
                               x-text="kunci === 'pembalikan'
                                    ? 'Penjualan yang batal & titipan pembeli yang dikembalikan. Kosong = penjualannya tetap sah (paket hilang, banding menang).'
                                    : 'Sisa titipan pesanan dicairkan: faktur dilunasi, dipotong biaya admin (termasuk Hemat Biaya Kirim) / pajak. Baris Saldo Penjualan ⚖ menyeimbangkan otomatis sampai diubah sendiri.'"></p>

                            {{-- Nilai penjualan yang dibalik. Bawaannya nilai barang (kecuali yang dananya
                                 diganti); ubah untuk refund hasil negosiasi — blok Pembalikan disusun
                                 ulang dan Penyelesaian mencairkan sisanya. --}}
                            <div x-show="kunci === 'pembalikan'" class="flex items-center gap-2 mb-3">
                                <label class="text-[11px] font-bold text-gray-500 uppercase tracking-widest">Nilai dibalik / refund</label>
                                <input type="text" x-model="dibalikInput" @change="aturDibalik()" @keydown.enter.prevent="aturDibalik()"
                                       inputmode="numeric" placeholder="0"
                                       class="rupiah-input w-36 border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm text-right bg-white">
                                <span class="text-[10px] text-gray-400" x-show="dibalikManual !== null">nilai hasil negosiasi</span>
                            </div>

                            {{-- Tujuan dana — hanya bila ada lebih dari satu pilihan masuk akal
                                 (pelanggan biasa / dana marketplace yang sudah cair). --}}
                            <div x-show="kunci === 'pembalikan' && Object.keys(bawaan.tujuan || {}).length > 1"
                                 class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3 bg-gray-50 rounded-xl p-3">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Pengembalian dana pembeli lewat</label>
                                    <select name="refund_target" x-model="refundTarget" @change="muatBawaan('pembalikan')"
                                            class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm bg-white">
                                        <template x-for="(label, key) in bawaan.tujuan" :key="key">
                                            <option :value="key" x-text="label" :selected="key === refundTarget"></option>
                                        </template>
                                    </select>
                                </div>
                                <div x-show="refundTarget === 'bank'">
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Dari akun kas/bank</label>
                                    <select name="refund_account_id" x-model="refundAccountId" @change="muatBawaan('pembalikan')"
                                            class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm bg-white">
                                        <option value="">— pilih akun —</option>
                                        @foreach($cashAccounts as $acc)
                                            <option value="{{ $acc->id }}">{{ $acc->code }} — {{ $acc->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div x-show="refundTarget === 'credit'">
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-widest mb-1">Kredit atas nama</label>
                                    <select name="refund_customer_id" x-model="refundCustomerId"
                                            class="w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-sm bg-white">
                                        <option value="">— pelanggan di faktur —</option>
                                        @foreach($customers as $cust)
                                            <option value="{{ $cust->id }}">{{ $cust->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-12 gap-2 px-1 mb-1 text-[10px] font-bold text-gray-400 uppercase tracking-widest">
                                <div class="col-span-6">Akun</div>
                                <div class="col-span-3 text-right">Debit</div>
                                <div class="col-span-3 text-right pr-8">Kredit</div>
                            </div>

                            {{-- Akun dicari lewat nama ATAU kode — orang hafal nama akun, bukan nomornya. --}}
                            <template x-for="(b, i) in (blok[kunci].rows || [])" :key="kunci + i">
                              <div>
                                {{-- Judul kelompok: muncul tiap kali kelompok baris berganti. --}}
                                <div x-show="i === 0 || grupBaris(kunci, blok[kunci].rows[i - 1]) !== grupBaris(kunci, b)"
                                     class="flex items-baseline gap-2 mt-3 mb-1.5 pl-2 border-l-2"
                                     :class="grupInfo(kunci, b).warna">
                                    <span class="text-[11px] font-black text-gray-700" x-text="grupInfo(kunci, b).judul"></span>
                                    <span class="text-[10px] text-gray-400" x-text="grupInfo(kunci, b).ket"></span>
                                </div>
                                <div class="grid grid-cols-12 gap-2 mb-2 items-start"
                                     x-data="{ buka: false, cari: '', sorot: 0 }">
                                    <div class="col-span-6 relative" @click.outside="buka = false">
                                        <input type="hidden" :name="`jurnal[${kunci}][${i}][account_id]`" :value="b.account_id" :disabled="!kirimBlok(kunci)">
                                        <input type="hidden" :name="`jurnal[${kunci}][${i}][memo]`" :value="b.memo || ''" :disabled="!kirimBlok(kunci)">
                                        <input type="text" x-ref="cariAkun"
                                               :value="buka ? cari : akunLabel(b.account_id)"
                                               @focus="buka = true; cari = ''; sorot = 0"
                                               @input="cari = $event.target.value; sorot = 0"
                                               @keydown.arrow-down.prevent="sorot = Math.min(sorot + 1, akunCocok(cari).length - 1)"
                                               @keydown.arrow-up.prevent="sorot = Math.max(sorot - 1, 0)"
                                               @keydown.enter.prevent="const a = akunCocok(cari)[sorot]; if (a) { b.account_id = String(a.id); ubahBlok(kunci); buka = false; $refs.cariAkun.blur(); }"
                                               @keydown.escape="buka = false; $refs.cariAkun.blur()"
                                               @keydown.tab="buka = false"
                                               placeholder="Cari nama atau kode akun…"
                                               class="w-full border rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-400"
                                               :class="b.account_id ? 'border-gray-200 text-gray-800 font-semibold' : 'border-amber-300'">
                                        <div class="text-[10px] text-gray-400 mt-0.5 pl-1 truncate" x-show="b.memo" x-text="(b.auto ? '⚖ ' : '') + b.memo"></div>
                                        <div x-show="buka" x-cloak
                                             class="absolute z-30 left-0 right-0 mt-1 max-h-64 overflow-y-auto border border-gray-200 rounded-lg bg-white shadow-lg">
                                            <template x-for="(a, n) in akunCocok(cari)" :key="a.id">
                                                <button type="button"
                                                        @mousedown.prevent="b.account_id = String(a.id); ubahBlok(kunci); buka = false; $refs.cariAkun.blur()"
                                                        @mouseenter="sorot = n"
                                                        class="w-full text-left px-3 py-2 text-sm flex gap-3"
                                                        :class="n === sorot ? 'bg-indigo-50' : ''">
                                                    <span class="font-mono text-gray-400 shrink-0" x-text="a.code"></span>
                                                    <span class="text-gray-700" x-text="a.name"></span>
                                                </button>
                                            </template>
                                            <div x-show="akunCocok(cari).length === 0" class="px-3 py-2 text-sm text-gray-400 italic">Akun tidak ditemukan</div>
                                        </div>
                                    </div>
                                    <input type="text" :name="`jurnal[${kunci}][${i}][debit]`" x-model="b.debit" :disabled="!kirimBlok(kunci)"
                                           @input="b.credit = ''; b.auto = false; ubahBlok(kunci)" placeholder="Debit" inputmode="numeric"
                                           class="rupiah-input col-span-3 border rounded-lg px-3 py-2 text-sm text-right bg-white"
                                           :class="b.auto ? 'border-emerald-200 bg-emerald-50/40' : 'border-gray-200'">
                                    <div class="col-span-3 flex items-center gap-1">
                                        <input type="text" :name="`jurnal[${kunci}][${i}][credit]`" x-model="b.credit" :disabled="!kirimBlok(kunci)"
                                               @input="b.debit = ''; b.auto = false; ubahBlok(kunci)" placeholder="Kredit" inputmode="numeric"
                                               class="rupiah-input flex-1 min-w-0 border rounded-lg px-3 py-2 text-sm text-right bg-white"
                                               :class="b.auto ? 'border-emerald-200 bg-emerald-50/40' : 'border-gray-200'">
                                        <button type="button" @click="blok[kunci].rows.splice(i, 1); ubahBlok(kunci)"
                                                class="text-gray-300 hover:text-red-500 w-7 text-lg font-bold" title="Hapus baris">×</button>
                                    </div>
                                </div>
                              </div>
                            </template>

                            <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-100">
                                <button type="button" @click="blok[kunci].rows.push({account_id: '', debit: '', credit: '', memo: '', auto: false}); ubahBlok(kunci)"
                                        class="text-xs font-bold text-indigo-600 hover:text-indigo-800">+ Tambah baris</button>
                                <div class="text-xs font-mono" :class="blokSeimbang(kunci) ? 'text-green-600' : 'text-red-600 font-bold'">
                                    <span x-show="totalBlok(kunci, 'debit') === 0 && totalBlok(kunci, 'credit') === 0">tanpa jurnal</span>
                                    <span x-show="totalBlok(kunci, 'debit') > 0 || totalBlok(kunci, 'credit') > 0"
                                          x-text="`D ${formatNumber(totalBlok(kunci, 'debit'))} · K ${formatNumber(totalBlok(kunci, 'credit'))}` + (blokSeimbang(kunci) ? ' · seimbang ✓' : ` · selisih ${formatNumber(Math.abs(totalBlok(kunci, 'debit') - totalBlok(kunci, 'credit')))}`)"></span>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Uji akhir yang sama dengan MarketplaceSiklusPenuhTest: setelah pesanan tuntas,
                         Piutang / Uang Muka / Saldo Ditahan pesanan ini WAJIB nol. --}}
                    <div class="rounded-xl border px-4 py-3 text-xs" x-show="cekSaldo().length"
                         :class="cekSaldo().every(c => Math.abs(c.sisa) < 1) ? 'border-green-200 bg-green-50/50' : 'border-amber-200 bg-amber-50/60'">
                        <div class="font-bold mb-1.5"
                             :class="cekSaldo().every(c => Math.abs(c.sisa) < 1) ? 'text-green-700' : 'text-amber-700'"
                             x-text="cekSaldo().every(c => Math.abs(c.sisa) < 1) ? '✓ Pesanan tuntas — semua saldo pesanan nol setelah retur ini' : '⚠ Sisa saldo pesanan setelah retur ini'"></div>
                        <template x-for="c in cekSaldo()" :key="c.label">
                            <div class="flex justify-between">
                                <span class="text-gray-600" x-text="c.label"></span>
                                <span class="font-mono" :class="Math.abs(c.sisa) < 1 ? 'text-gray-500' : (c.sisa < 0 ? 'text-red-600 font-bold' : 'text-amber-700 font-bold')"
                                      x-text="`${formatNumber(c.awal)} → ${formatNumber(c.sisa)}`"></span>
                            </div>
                        </template>
                        <p class="text-[10px] text-gray-500 mt-1.5" x-show="!cekSaldo().every(c => Math.abs(c.sisa) < 1)">
                            Sisa positif = masih menunggu (mis. pesanan selesai dari marketplace). Sisa minus akan ditolak saat posting.
                        </p>
                    </div>
                </div>

                {{-- Retur LAMA atas SO: jurnalnya tetap cara lama (membalik Uang Muka). --}}
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-xs text-amber-700" x-show="selectedDoc && returnType === 'so'" x-cloak>
                    Retur lama atas Sales Order — jurnalnya dihitung cara lama (Dr Uang Muka / Cr titipan pembeli).
                    Pindahkan ke faktur untuk memakai jurnal tiga blok.
                </div>

                {{-- ═══ RINGKASAN + TOMBOL (bawah form) ═══
                     Tombol duduk setelah jurnal: orang baru menyelesaikan setelah membaca
                     seluruh isian, bukan dari pojok kanan atas. Pemindah tahap (Banding)
                     bersebelahan dengan tombol yang menyelesaikan — dua jalan keluar sebuah
                     kasus harus terlihat bersamaan. --}}
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5" x-show="selectedDoc" x-transition>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
                        <div>
                            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Nilai barang diretur</div>
                            <div class="font-bold text-gray-800">Rp <span x-text="formatNumber(summary.net)"></span></div>
                        </div>
                        <div x-show="returnType === 'invoice'">
                            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Penjualan dibalik</div>
                            <div class="font-bold" :class="nilaiDibalik() > 0 ? 'text-blue-600' : 'text-green-600'"
                                 x-text="nilaiDibalik() > 0 ? 'Rp ' + formatNumber(nilaiDibalik()) : 'tidak dibalik'"></div>
                        </div>
                        <div x-show="returnType === 'invoice' && blok.penyelesaian.rows !== null">
                            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Dana cair ke Saldo Penjualan</div>
                            <div class="font-bold" :class="danaCair() < 0 ? 'text-red-600' : 'text-gray-800'" x-text="'Rp ' + formatNumber(danaCair())"></div>
                        </div>
                        <div x-show="returnType === 'invoice' && blok.penyelesaian.rows !== null">
                            <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Potongan marketplace</div>
                            <div class="font-bold text-gray-800" x-text="'Rp ' + formatNumber(potonganMarketplace())"></div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 pt-4 border-t border-gray-100">
                        <a href="{{ route('pos.fulfillment.retur') }}"
                           class="px-4 py-3 rounded-xl font-semibold text-sm text-gray-400 hover:text-gray-600">Batal &amp; Kembali</a>

                        <div class="flex-1"></div>

                        @isset($return)
                            @if($return->status === 'draft')
                                {{-- Retur tidak jadi: pembeli tak mengirim barangnya & marketplace
                                     menjadikannya pesanan biasa. Draft ditutup tanpa jurnal, faktur
                                     yang belum cair dicairkan (biaya admin taksiran). --}}
                                <button type="button" onclick="returTidakJadi()"
                                        class="px-4 py-3 rounded-xl font-bold text-sm border border-red-200 text-red-600 hover:bg-red-50 transition-all">
                                    ✕ Retur Tidak Jadi
                                </button>
                                <button type="button" @click="simpanLaluPindah('{{ $return->stage === 'banding' ? 'baru' : 'banding' }}')"
                                        class="px-5 py-3 rounded-xl font-bold text-sm border transition-all
                                               {{ $return->stage === 'banding'
                                                    ? 'border-gray-300 text-gray-600 hover:bg-gray-50'
                                                    : 'border-amber-400 text-amber-700 hover:bg-amber-50' }}">
                                    {{ $return->stage === 'banding' ? '← Kembalikan ke Retur Baru' : '⚖ Ajukan Banding' }}
                                </button>
                            @endif
                        @endisset

                        <button type="button" @click="saveDraft()" :disabled="!canSubmit()"
                                class="px-5 py-3 rounded-xl font-bold text-sm transition-all border border-gray-200"
                                :class="canSubmit() ? 'bg-white text-gray-700 hover:bg-gray-50' : 'bg-gray-50 text-gray-300 cursor-not-allowed'">
                            <span x-text="returnId ? '💾 Perbarui Draf' : '💾 Simpan Draf'"></span>
                        </button>

                        <button type="button" @click="confirmAndSubmit()" :disabled="!canSubmit()"
                                class="px-6 py-3 rounded-xl font-black text-sm transition-all shadow-lg"
                                :class="canSubmit()
                                    ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-blue-200 active:scale-95'
                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed shadow-none'">
                            ✓ SELESAIKAN RETUR
                        </button>
                    </div>
                    <p class="text-[11px] text-red-600 font-bold mt-2 text-right">Data permanen setelah retur diselesaikan.</p>
                </div>

            </div>

            {{-- RIGHT COLUMN — panduan jurnal per kasus. Kasus yang sedang dikerjakan
                 (dari jenis, hasil banding, kondisi & qty barang) terbuka dan disorot;
                 kasus lain bisa dibuka untuk perbandingan. --}}
            <div class="col-span-4">
                <div class="sticky top-4 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" x-show="selectedDoc" x-transition>
                    <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-slate-50 to-white">
                        <h3 class="font-bold text-gray-700">📘 Panduan Jurnal Retur</h3>
                        <p class="text-[11px] text-gray-500 mt-0.5">Isi bawaan di langkah 3 mengikuti panduan ini — ubah angkanya sesuai Seller Centre.</p>
                    </div>

                    <div class="max-h-[calc(100vh-7rem)] overflow-y-auto p-4 space-y-3">
                        {{-- Peringatan: isian yang bertentangan dengan jenis kasusnya. --}}
                        <template x-for="w in peringatanKasus()" :key="w">
                            <div class="rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] text-amber-800" x-text="'⚠ ' + w"></div>
                        </template>

                        <template x-for="grup in panduanGrup()" :key="grup.nama">
                            <div>
                                <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 mt-1" x-text="grup.nama"></div>
                                <div class="space-y-1.5">
                                    <template x-for="k in grup.kasus" :key="k.key">
                                        <div class="rounded-xl border transition-colors"
                                             :class="k.key === kasusAktif() ? 'border-blue-400 bg-blue-50/60 shadow-sm' : 'border-gray-100'">
                                            <button type="button" @click="panduanBuka = panduanBuka === k.key ? null : k.key"
                                                    class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left">
                                                <span class="text-xs font-bold" :class="k.key === kasusAktif() ? 'text-blue-800' : 'text-gray-700'" x-text="k.judul"></span>
                                                <span class="flex items-center gap-1.5 shrink-0">
                                                    <span x-show="k.key === kasusAktif()" class="text-[9px] font-black bg-blue-600 text-white px-1.5 py-0.5 rounded">KASUS INI</span>
                                                    <span x-show="k.key === 'K5' && danaSudahCair() && kasusAktif() !== 'K5'" class="text-[9px] font-black bg-amber-500 text-white px-1.5 py-0.5 rounded">TERDETEKSI</span>
                                                    <span class="text-gray-400 text-xs" x-text="panduanTerbuka(k.key) ? '▾' : '▸'"></span>
                                                </span>
                                            </button>
                                            <div x-show="panduanTerbuka(k.key)" class="px-3 pb-3 text-[11px] space-y-2">
                                                <p class="text-gray-500 leading-relaxed" x-text="k.kapan"></p>
                                                <div x-show="k.key === kasusAktif() && k.key !== 'K5' && danaSudahCair()"
                                                     class="rounded-md bg-blue-50 border border-blue-200 px-2 py-1.5 text-blue-900 leading-relaxed">
                                                    <span class="font-black">ℹ Dana pesanan ini sudah cair ke kita.</span>
                                                    Saldo ditahannya sudah 0, jadi uang untuk pembeli dipotong dari Saldo Penjualan dan tidak ada blok Penyelesaian —
                                                    blok Pembalikan mengikuti panduan "Dana pesanan sudah cair ke kita".
                                                </div>
                                                <div class="rounded-md bg-amber-50 border border-amber-100 px-2 py-1.5 text-amber-900 leading-relaxed">
                                                    <span class="font-black">💰 Uang:</span> <span x-text="k.uang"></span>
                                                </div>
                                                <div class="text-gray-600 leading-relaxed"><span class="font-bold">📦 Barang:</span> <span x-text="k.barang"></span></div>
                                                <template x-for="bag in [['a. HPP', k.hpp], ['b. Pembalikan', k.pembalikan], ['c. Penyelesaian', k.penyelesaian]]" :key="bag[0]">
                                                    <div>
                                                        <div class="font-black text-gray-500 text-[10px] uppercase tracking-wider" x-text="bag[0]"></div>
                                                        <div x-show="!bag[1].length" class="text-gray-400 italic">tanpa jurnal</div>
                                                        <template x-for="(l, n) in bag[1]" :key="n">
                                                            <div class="font-mono flex gap-1.5" :class="l[0] === 'Cr' ? 'pl-4 text-gray-500' : 'text-gray-800'">
                                                                <span class="font-bold w-5 shrink-0" x-text="l[0]"></span>
                                                                <span class="font-sans" x-text="l[1]"></span>
                                                                <span class="font-sans text-gray-400" x-show="l[2]" x-text="'— ' + l[2]"></span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                                <p x-show="k.catatan" class="text-gray-500 bg-white/70 border border-gray-100 rounded-lg px-2 py-1.5 leading-relaxed" x-text="'💡 ' + k.catatan"></p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </form>

    @isset($return)
        @if($return->status === 'draft')
            {{-- Di luar form retur: form tak boleh bersarang. --}}
            <form id="batalForm" method="POST" action="{{ route('sales.returns.batal', $return->id) }}" class="hidden">
                @csrf
                <input type="hidden" name="alasan" id="batalAlasan">
            </form>
            <script>
                function returTidakJadi() {
                    const alasan = prompt(
                        'RETUR TIDAK JADI — draft ditutup tanpa jurnal & stok, pesanan kembali jadi pesanan biasa, '
                        + 'dan faktur yang belum cair langsung dicairkan (biaya admin taksiran). '
                        + 'Tulis alasannya, mis. "barang tidak dikirim pembeli, Shopee jadikan pesanan normal":'
                    );
                    if (alasan === null) return;
                    if (alasan.trim().length < 5) { alert('Alasan minimal 5 huruf.'); return; }
                    document.getElementById('batalAlasan').value = alasan.trim();
                    document.getElementById('batalForm').submit();
                }
            </script>
        @endif
    @endisset

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
                    <span class="font-bold text-gray-800" x-text="getCustomerName() || '—'"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500" x-text="returnType === 'invoice' ? 'Faktur' : 'Sales Order'"></span>
                    <span class="font-bold text-gray-800" x-text="selectedDoc?.number"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Nilai barang diretur</span>
                    <span class="font-black text-blue-600">Rp <span x-text="formatNumber(summary.net)"></span></span>
                </div>
                <div class="flex justify-between" x-show="returnType === 'invoice'">
                    <span class="text-gray-500">Penjualan dibalik</span>
                    <span class="font-bold text-gray-800" x-text="nilaiDibalik() > 0 ? 'Rp ' + formatNumber(nilaiDibalik()) : 'tidak dibalik'"></span>
                </div>
                <div class="flex justify-between" x-show="returnType === 'invoice' && blok.penyelesaian.rows !== null">
                    <span class="text-gray-500">Dana cair ke Saldo Penjualan</span>
                    <span class="font-bold text-gray-800" x-text="'Rp ' + formatNumber(danaCair())"></span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="showConfirm = false"
                        class="flex-1 py-2.5 border border-gray-200 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition">
                    Batal
                </button>
                <button type="button" @click="doSubmit()"
                        class="flex-1 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-black hover:bg-blue-700 transition active:scale-95 shadow-lg shadow-blue-200">
                    Ya, Selesaikan Retur
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
/**
 * @param initialData retur TERSIMPAN yang sedang diedit (mode edit)
 * @param prefill     pelanggan + faktur yang sudah diketahui pemanggil (retur BARU dari
 *                    kartu Pemrosesan Pesanan). Bukan dokumen tersimpan: returnId tetap
 *                    null, jadi simpan tetap membuat retur baru, dan dokumennya masih
 *                    boleh diganti seperti biasa.
 */
const KAS_AKUN = @json(collect($cashAccounts ?? [])->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values());
const AKUN_JURNAL = @json(($akunJurnal ?? collect())->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values());
const URL_JURNAL_BAWAAN = @json(route('sales.ajax.returns.jurnal_bawaan'));
const KASUS = @json(\App\Modules\Sales\Models\SalesReturn::CASES);
const KASUS_BANDING = @json(\App\Modules\Sales\Models\SalesReturn::CASE_APPEAL);
/** Kasus yang barangnya pasti tak sampai ke gudang kita → kondisi bawaan "Tidak Kembali". */
const KASUS_TIDAK_KEMBALI = ['PH1', 'PH2', 'K3', 'K4'];

/*
 * Panduan jurnal per kasus — CERMIN SalesReturnService::jurnalBawaan(). Kalau aturan bawaan
 * di service berubah, ubah juga di sini. Baris: [Dr|Cr, akun, nilai].
 */
const PANDUAN = [
    { grup: 'Paket Hilang', key: 'PH1', judul: 'Klaim menang — dana diganti ke kita',
      kapan: 'Paket hilang di jalan. Marketplace menyetujui klaim dan membayar penggantinya.',
      uang: 'Uang pembeli TETAP jadi milik kita — marketplace mencairkannya ke Saldo Penjualan (penuh, tanpa biaya admin). Pembeli tidak dikembalikan dananya oleh kita.',
      barang: 'Tidak sampai ke pembeli & tidak kembali ke kita → semua baris "Tidak Kembali" (terisi otomatis).',
      hpp: [['Dr', 'Beban Kerugian Retur', 'modal barang yang hilang'], ['Cr', 'HPP', '']], pembalikan: [],
      penyelesaian: [['Dr', 'Uang Muka', 'uang pembeli dipakai melunasi faktur'], ['Cr', 'Piutang Usaha', 'faktur lunas'],
                     ['Dr', 'Saldo Penjualan', 'dana pengganti yang masuk ke kita'], ['Dr', 'Beban Admin', 'Hemat Biaya Kirim (bila dipotong)'],
                     ['Dr', 'PPh Final 0,5%', 'pajak dipotong marketplace'], ['Cr', 'Saldo Ditahan', 'dana dilepas marketplace ke kita']],
      catatan: 'Penjualan tidak dibatalkan. Isi potongan Program Hemat Biaya Kirim (Biaya Lainnya di Seller Centre) di baris Beban Admin — Saldo Penjualan menyesuaikan sendiri.' },
    { grup: 'Paket Hilang', key: 'PH2', judul: 'Klaim ditolak — dana kembali ke pembeli',
      kapan: 'Paket hilang, tapi marketplace menolak klaim kita dan mengembalikan uangnya ke pembeli.',
      uang: 'Uang dikembalikan marketplace ke PEMBELI. Kita tidak menerima apa pun; potongan Hemat Biaya Kirim tetap ditagih ke kita.',
      barang: 'Hilang, tidak kembali ke kita → "Tidak Kembali".',
      hpp: [['Dr', 'Beban Kerugian Retur', 'modal barang yang hilang'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'penjualan dibatalkan'], ['Cr', 'Piutang Usaha', 'tagihan faktur dihapus'],
                   ['Dr', 'Uang Muka', 'uang pembeli yang ditahan…'], ['Cr', 'Saldo Ditahan', '…dikembalikan marketplace ke pembeli']],
      penyelesaian: [['Dr', 'Beban Admin', 'Hemat Biaya Kirim (bila dipotong)'], ['Cr', 'Saldo Penjualan', 'dipotong dari saldo kita']],
      catatan: '' },

    { grup: 'Gagal Kirim', key: 'GK1', judul: 'Kalah / tidak banding — dana kembali ke pembeli',
      kapan: 'Paket gagal diterima pembeli dan kembali ke gudang kita. Tidak ada penggantian dari marketplace.',
      uang: 'Uang dikembalikan marketplace ke PEMBELI. Kita tidak menerima apa pun; potongan Hemat Biaya Kirim tetap ditagih ke kita.',
      barang: 'Kembali ke gudang kita → isi kondisi hasil cek paket: Utuh / Perbaikan / Rusak.',
      hpp: [['Dr', 'Persediaan · Persediaan Perbaikan · Beban Kerugian Retur', 'sesuai kondisi barang'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'penjualan dibatalkan'], ['Cr', 'Piutang Usaha', 'tagihan faktur dihapus'],
                   ['Dr', 'Uang Muka', 'uang pembeli yang ditahan…'], ['Cr', 'Saldo Ditahan', '…dikembalikan marketplace ke pembeli']],
      penyelesaian: [['Dr', 'Beban Admin', 'Hemat Biaya Kirim'], ['Cr', 'Saldo Penjualan', 'dipotong dari saldo kita → jadi minus']],
      catatan: 'Saldo Penjualan pesanan ini boleh minus sebesar Hemat Biaya Kirim — memang begitu praktiknya di Seller Centre.' },
    { grup: 'Gagal Kirim', key: 'GK2', judul: 'Banding menang — dana diganti ke kita',
      kapan: 'Paket kembali (sering rusak di jalan), kita banding, dan marketplace membayar penggantinya.',
      uang: 'Uang pembeli TETAP jadi milik kita — marketplace mencairkannya ke Saldo Penjualan (penuh, tanpa biaya admin).',
      barang: 'Kembali ke gudang kita → isi kondisi hasil cek paket.',
      hpp: [['Dr', 'Persediaan · Persediaan Perbaikan · Beban Kerugian Retur', 'sesuai kondisi barang'], ['Cr', 'HPP', '']],
      pembalikan: [],
      penyelesaian: [['Dr', 'Uang Muka', 'uang pembeli dipakai melunasi faktur'], ['Cr', 'Piutang Usaha', 'faktur lunas'],
                     ['Dr', 'Saldo Penjualan', 'dana pengganti yang masuk ke kita'], ['Dr', 'Beban Admin', 'Hemat Biaya Kirim (bila dipotong)'],
                     ['Dr', 'PPh Final 0,5%', 'pajak dipotong marketplace'], ['Cr', 'Saldo Ditahan', 'dana dilepas marketplace ke kita']],
      catatan: 'Penjualan tidak dibatalkan — sama seperti paket hilang yang klaimnya menang.' },

    { grup: 'Diajukan Konsumen', key: 'K1', judul: 'Barang kembali, dana penuh ke pembeli',
      kapan: 'Pembeli mengajukan retur, mengirim balik SEMUA barangnya, dan disetujui.',
      uang: 'Seluruh uang dikembalikan marketplace ke PEMBELI (dana belum sempat cair ke kita). Kita tidak menerima apa pun.',
      barang: 'Kembali ke gudang kita → isi kondisi saat paket dibuka.',
      hpp: [['Dr', 'Persediaan · Perbaikan · Kerugian Retur', 'sesuai kondisi barang'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'penjualan dibatalkan'], ['Cr', 'Piutang Usaha', 'tagihan faktur dihapus'],
                   ['Dr', 'Uang Muka', 'uang pembeli yang ditahan…'], ['Cr', 'Saldo Ditahan', '…dikembalikan marketplace ke pembeli']],
      penyelesaian: [['Dr', 'Beban Admin', 'Hemat Biaya Kirim (bila dipotong)'], ['Cr', 'Saldo Penjualan', 'dipotong dari saldo kita']],
      catatan: 'Pesanan yang diretur biasanya tetap dipotong Program Hemat Biaya Kirim — isi di baris Beban Admin; Saldo Penjualan jadi minus sebesar itu.' },
    { grup: 'Diajukan Konsumen', key: 'K2', judul: 'Sebagian barang kembali, dana sebagian ke pembeli',
      kapan: 'Pembeli mengembalikan SEBAGIAN barang; sisanya tetap dibeli.',
      uang: 'Uang untuk barang yang dikembalikan → ke PEMBELI. Uang untuk barang yang tetap dibeli → ke KITA (masuk Saldo Penjualan, dipotong admin & pajak).',
      barang: 'Isi qty yang kembali + kondisinya; baris lain biarkan 0.',
      hpp: [['Dr', 'Persediaan · Perbaikan · Kerugian Retur', 'qty yang kembali'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'bagian yang dibatalkan'], ['Cr', 'Piutang Usaha', 'tagihan bagian itu dihapus'],
                   ['Dr', 'Uang Muka', 'uang pembeli bagian itu…'], ['Cr', 'Saldo Ditahan', '…dikembalikan ke pembeli']],
      penyelesaian: [['Dr', 'Uang Muka', 'sisa uang pembeli melunasi faktur'], ['Cr', 'Piutang Usaha', 'faktur lunas'],
                     ['Dr', 'Saldo Penjualan', 'sisa dana yang masuk ke kita'], ['Dr', 'Beban Admin', ''], ['Dr', 'PPh Final 0,5%', ''],
                     ['Cr', 'Saldo Ditahan', 'sisa dana dilepas ke kita']],
      catatan: 'Sesuaikan biaya admin dengan Seller Centre.' },
    { grup: 'Diajukan Konsumen', key: 'K3', judul: 'Barang tidak dikirim balik, dana penuh ke pembeli',
      kapan: 'Retur disetujui marketplace tanpa pembeli mengirim balik barangnya.',
      uang: 'Seluruh uang dikembalikan marketplace ke PEMBELI. Kita tidak menerima apa pun.',
      barang: 'Tetap di pembeli, tidak kembali ke kita → "Tidak Kembali".',
      hpp: [['Dr', 'Beban Kerugian Retur', 'modal barang yang tak kembali'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'penjualan dibatalkan'], ['Cr', 'Piutang Usaha', 'tagihan faktur dihapus'],
                   ['Dr', 'Uang Muka', 'uang pembeli yang ditahan…'], ['Cr', 'Saldo Ditahan', '…dikembalikan marketplace ke pembeli']],
      penyelesaian: [],
      catatan: 'Kalau Seller Centre tetap memotong Hemat Biaya Kirim, tambahkan Dr Beban Admin / Cr Saldo Penjualan di blok Penyelesaian.' },
    { grup: 'Diajukan Konsumen', key: 'K4', judul: 'Barang tetap di pembeli, dana sebagian ke pembeli',
      kapan: 'Hasil negosiasi: pembeli menyimpan barangnya dan hanya sebagian uang dikembalikan.',
      uang: 'Nilai refund → ke PEMBELI. Sisanya → ke KITA (masuk Saldo Penjualan, dipotong admin & pajak).',
      barang: 'Tetap di pembeli → "Tidak Kembali".',
      hpp: [['Dr', 'Beban Kerugian Retur', 'modal barang'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'sebesar refund'], ['Cr', 'Piutang Usaha', 'tagihan sebesar refund dihapus'],
                   ['Dr', 'Uang Muka', 'uang pembeli sebesar refund…'], ['Cr', 'Saldo Ditahan', '…dikembalikan ke pembeli']],
      penyelesaian: [['Dr', 'Uang Muka', 'sisa uang pembeli melunasi faktur'], ['Cr', 'Piutang Usaha', 'faktur lunas'],
                     ['Dr', 'Saldo Penjualan', 'sisa dana yang masuk ke kita'], ['Dr', 'Beban Admin', ''], ['Dr', 'PPh Final 0,5%', ''],
                     ['Cr', 'Saldo Ditahan', 'sisa dana dilepas ke kita']],
      catatan: 'Isi "Nilai dibalik / refund" di blok Pembalikan dengan nilai yang dikembalikan ke pembeli — kedua blok disusun ulang otomatis.' },
    { grup: 'Diajukan Konsumen', key: 'K5', judul: 'Dana pesanan sudah cair ke kita',
      kapan: 'Retur terjadi SETELAH dana pesanan masuk Saldo Penjualan kita (terdeteksi otomatis).',
      uang: 'Uang untuk PEMBELI diambil marketplace dari Saldo Penjualan KITA.',
      barang: 'Sesuai kondisi barang.',
      hpp: [['Dr', 'Persediaan · Perbaikan · Kerugian Retur', 'sesuai kondisi barang'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'penjualan dibatalkan'], ['Cr', 'Saldo Penjualan', 'dipotong dari saldo kita untuk pembeli'],
                   ['Cr', 'Beban Admin', 'bila biaya admin dikembalikan marketplace']],
      penyelesaian: [],
      catatan: 'Tidak ada blok Penyelesaian — pesanannya sudah tuntas sebelumnya.' },
    { grup: 'Diajukan Konsumen', key: 'K6', judul: 'Banding menang — dana tetap ke kita',
      kapan: 'Pembeli mengajukan retur, tapi marketplace menolaknya setelah kita banding.',
      uang: 'Uang TETAP jadi milik kita — cair ke Saldo Penjualan seperti penjualan biasa (dipotong admin & pajak). Pembeli tidak menerima refund.',
      barang: 'Bila tidak dikirim balik → "Tidak Kembali"; bila kembali → sesuai kondisi.',
      hpp: [['Dr', 'Beban Kerugian Retur', 'modal barang yang tak kembali'], ['Cr', 'HPP', '']], pembalikan: [],
      penyelesaian: [['Dr', 'Uang Muka', 'uang pembeli dipakai melunasi faktur'], ['Cr', 'Piutang Usaha', 'faktur lunas'],
                     ['Dr', 'Saldo Penjualan', 'dana yang masuk ke kita'], ['Dr', 'Beban Admin', ''], ['Dr', 'PPh Final 0,5%', ''],
                     ['Cr', 'Saldo Ditahan', 'dana dilepas marketplace ke kita']],
      catatan: 'Beda dengan paket hilang: ini penjualan biasa, jadi biaya admin tetap ditagih.' },
    { grup: 'Diajukan Konsumen', key: 'K7', judul: 'Pelanggan non-marketplace',
      kapan: 'Toko, web, reseller — tidak ada saldo ditahan marketplace (terdeteksi otomatis).',
      uang: 'KITA yang mengembalikan uang ke pembeli: tagihan yang belum dibayar dihapus; yang sudah dibayar dikembalikan tunai/transfer atau dijadikan kredit belanja.',
      barang: 'Sesuai kondisi barang.',
      hpp: [['Dr', 'Persediaan · Perbaikan · Kerugian Retur', 'sesuai kondisi barang'], ['Cr', 'HPP', '']],
      pembalikan: [['Dr', 'Retur Penjualan', 'penjualan dibatalkan'], ['Cr', 'Piutang Usaha', 'tagihan yang belum dibayar dihapus'],
                   ['Cr', 'Kas/Bank · Kredit Pelanggan', 'uang yang sudah dibayar dikembalikan ke pembeli']],
      penyelesaian: [],
      catatan: 'Pilih "Pengembalian dana pembeli lewat" di blok Pembalikan: Kredit Pelanggan (tanpa uang keluar) atau Kas/Bank.' },
];


/** Baris jurnal tersimpan (angka) → baris form (teks berformat ribuan). */
function barisForm(rows) {
    if (rows === null || rows === undefined) return null;
    const f = v => (Number(v) > 0 ? window.formatThousands(Math.round(Number(v))) : '');
    return rows.map(r => ({
        account_id: r.account_id ? String(r.account_id) : '',
        debit: f(r.debit), credit: f(r.credit),
        memo: r.memo || '', auto: !!r.auto,
    }));
}

function returForm(initialData = null, prefill = null) {
    // Mode edit menang: kalau retur tersimpan ada, isian di muka tak berlaku lagi.
    if (initialData) prefill = null;

    return {
        // State
        returnId: initialData?.id || null,
        formStatus: 'posted',
        customerId: initialData?.customer_id || prefill?.customer_id || null,
        customerName: initialData?.customer?.name || prefill?.customer?.name || '',
        returnType: initialData?.invoice_id ? 'invoice' : (initialData?.sales_order_id ? 'so' : 'invoice'),
        documents: [],
        selectedDocId: initialData?.invoice_id || initialData?.sales_order_id || prefill?.invoice_id || null,
        selectedDoc: null,
        nomorTersalin: false,
        items: [],
        returnDate: initialData?.return_date ? initialData.return_date.split('T')[0] : '{{ now()->format("Y-m-d") }}',
        today: '{{ now()->format("Y-m-d") }}',

        // Definisi kasus retur. Dinamai caseType, BUKAN returnType — nama itu sudah dipakai
        // di atas untuk memilih dokumen sumber (invoice vs SO).
        caseType: initialData?.return_type || '',
        externalReturnNo: initialData?.external_return_number || '',
        caseNotes: initialData?.notes || '',
        customerBalance: initialData?.customer?.credit_balance || 0,

        // Tujuan dana — dipilih di blok Pembalikan, hanya bila ada lebih dari satu pilihan
        // masuk akal (pelanggan biasa / dana marketplace yang sudah cair).
        refundTarget: initialData?.refund_target || '',
        refundAccountId: initialData?.refund_account_id ? String(initialData.refund_account_id) : '',
        refundCustomerId: initialData?.refund_customer_id ? String(initialData.refund_customer_id) : '',
        returnCase: initialData?.return_case || '',
        /** Hasil banding tersirat dari kasus (sama dengan SalesReturn::CASE_APPEAL). */
        get appealResult() { return KASUS_BANDING[this.returnCase] || ''; },
        isMarketplace: initialData?.customer?.is_marketplace || prefill?.customer?.is_marketplace || false,
        marketplaceHoldName: initialData?.customer?.marketplace_hold_name || prefill?.customer?.marketplace_hold_name || '',

        /*
         * Jurnal tiga blok. `diubah` = admin sudah menyentuh bloknya: bawaan sistem tak lagi
         * menimpanya, dan hanya blok yang diubah yang ikut tersimpan di draft. Draft lama yang
         * dananya ditulis tangan (journal_override) masuk sebagai blok Pembalikan.
         */
        blok: {
            pembalikan: {
                rows: barisForm(initialData?.journal_reversal ?? (initialData?.journal_override?.length ? initialData.journal_override : null)) || [],
                diubah: initialData?.journal_reversal != null || !!initialData?.journal_override?.length,
            },
            penyelesaian: {
                rows: barisForm(initialData?.journal_settlement ?? null),
                diubah: initialData?.journal_settlement != null,
            },
        },
        bawaan: { tujuan: {}, konteks: null, dibalik: 0 },
        dibalikManual: initialData?.reversed_amount != null && initialData?.journal_reversal != null ? Number(initialData.reversed_amount) : null,
        dibalikInput: '',
        labelAkun: {},
        memuatBawaan: false,
        panduanBuka: null,
        pindahTahap: '',
        bawaanTimer: null,
        bawaanSeq: 0,

        // UI
        loadingDocs: false,
        // Daftar penuh dokumen pelanggan sudah ditarik? Di mode edit sengaja belum —
        // hanya satu dokumen yang dimuat agar halaman langsung terbuka.
        fullDocsLoaded: false,
        // Server hanya mengirim 50 dokumen terbaru; yang lebih lama dicari lewat `?q=`.
        searchingDocs: false,
        docSearchTimer: null,
        docSearchSeq: 0,
        loadingBalance: false,
        showConfirm: false,

        // Search UI State
        customerQuery: initialData?.customer?.name || prefill?.customer?.name || '',
        customerResults: [],
        showCustomerDropdown: false,
        docQuery: initialData?.invoice?.invoice_number || initialData?.sales_order?.order_number || prefill?.invoice?.invoice_number || '',
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
            reversed: 0,
            cogs: 0,
            conditionTotals: {
                good: 0,
                repair: 0,
                damaged: 0,
            },
        },
        journalPreview: {
            conditions: [],
        },

        async init() {
            if (this.caseType && !this.returnCase) this.returnCase = Object.keys(KASUS[this.caseType] || {})[0] || '';
            // Watch for changes to recalculate
            // Qty berubah -> nilai retur berubah -> nominal pengembalian bawaannya ikut.
            this.$watch('items', () => { this.calculateSummary(); this.muatBawaan(); }, { deep: true });

            if (this.returnId && initialData) {
                // We are in EDIT mode (from Draft)
                this.loadingDocs = true;
                try {
                    // 1. Ambil HANYA dokumen yang sudah melekat pada retur ini (`&id=`).
                    //    Menarik seluruh daftar pesanan pelanggan cuma untuk mencari satu baris
                    //    membuat halaman menggantung lama pada pelanggan marketplace yang punya
                    //    ribuan pesanan. Daftar lengkap baru dimuat kalau dokumennya mau diganti
                    //    (lihat ensureDocumentsLoaded).
                    const base = this.returnType === 'invoice'
                        ? `{{ route('sales.ajax.returns.invoices') }}`
                        : `{{ route('sales.ajax.returns.orders') }}`;

                    const res = await fetch(`${base}?customer_id=${this.customerId}&id=${this.selectedDocId}`);
                    this.documents = await res.json();

                    // 2. Select the current document
                    this.selectedDoc = this.documents.find(d => d.id == this.selectedDocId);
                    
                    if (this.selectedDoc) {
                        // 3. Kelompokkan baris retur tersimpan per item dokumen. Satu produk bisa
                        //    punya beberapa baris (kondisi berbeda) → hidrasi ke mode "pisah kondisi".
                        const grouped = {};
                        (initialData.items || []).forEach(ri => {
                            (grouped[ri.reference_item_id] ??= []).push(ri);
                        });

                        this.items = this.selectedDoc.items.map(docItem => {
                            const rows = grouped[docItem.id] || [];
                            let saved = null;
                            if (rows.length === 1) {
                                saved = { qty: rows[0].qty, condition: ['hilang', 'tetap'].includes(rows[0].condition) ? 'tidak_kembali' : (rows[0].condition || 'good'),
                                          component_conditions: rows[0].component_conditions || null };
                            } else if (rows.length > 1) {
                                // Beberapa baris kondisi (produk non-bundle) → mode pisah.
                                const splits = { good: 0, repair: 0, damaged: 0, tidak_kembali: 0 };
                                rows.forEach(r => {
                                const c = ['hilang', 'tetap'].includes(r.condition) ? 'tidak_kembali' : r.condition;
                                if (splits[c] !== undefined) splits[c] += parseFloat(r.qty) || 0;
                            });
                                saved = { split: true, splits };
                            }
                            return this.buildItem(docItem, saved);
                        });
                    }
                    
                    await this.refreshBalance();
                    this.calculateSummary(); // 🔥 TRIGGER INITIAL CALCULATION
                    this.muatBawaan();
                } catch (e) {
                    console.error('Error initializing edit form:', e);
                } finally {
                    this.loadingDocs = false;
                }
            } else if (prefill?.invoice_id) {
                /*
                 * Retur BARU yang pelanggan & fakturnya sudah diketahui. Sama seperti
                 * mode edit, yang ditarik cuma SATU dokumen (`&id=`) — menarik seluruh
                 * faktur pelanggan marketplace cuma untuk menemukan satu baris membuat
                 * halaman menggantung lama. Daftar penuh baru dimuat kalau fakturnya
                 * memang mau diganti (ensureDocumentsLoaded).
                 */
                this.loadingDocs = true;
                try {
                    const res = await fetch(`{{ route('sales.ajax.returns.invoices') }}?customer_id=${this.customerId}&id=${this.selectedDocId}`);
                    this.documents = await res.json();

                    // Baris barang ikut terisi, qty-nya tetap keputusan CS saat cek barang.
                    this.onDocumentChange(this.selectedDocId);
                    await this.refreshBalance();
                } catch (e) {
                    console.error('Error initializing prefilled form:', e);
                } finally {
                    this.loadingDocs = false;
                }
            }
        },

        /**
         * Muat daftar lengkap dokumen pelanggan — hanya saat orangnya benar-benar hendak
         * MENGGANTI dokumen sumber. Di mode edit kita sengaja hanya memuat satu dokumen
         * supaya halaman langsung terbuka; kalau tidak dipanggil di sini, kotak pencarian
         * dokumen akan tampak kosong padahal cuma belum dimuat.
         */
        async ensureDocumentsLoaded() {
            if (this.fullDocsLoaded || this.loadingDocs || !this.customerId) return;

            this.loadingDocs = true;
            try {
                const base = this.returnType === 'invoice'
                    ? `{{ route('sales.ajax.returns.invoices') }}`
                    : `{{ route('sales.ajax.returns.orders') }}`;

                const res  = await fetch(`${base}?customer_id=${this.customerId}`);
                const list = await res.json();

                // Dokumen yang sedang dipakai belum tentu lolos saringan kelayakan daftar
                // (mis. sudah punya retur draft) — jangan sampai hilang dari pilihan.
                const dipakai = this.documents.find(d => d.id == this.selectedDocId);
                if (dipakai && !list.some(d => d.id == dipakai.id)) list.unshift(dipakai);

                this.documents = list;
                this.fullDocsLoaded = true;
            } catch (e) {
                console.error('Gagal memuat daftar dokumen:', e);
            } finally {
                this.loadingDocs = false;
            }
        },

        /**
         * Cari dokumen di server sesuai ketikan (jeda 300 ms). Daftar dari server dibatasi
         * 50 terbaru — pelanggan marketplace punya ribuan faktur dan memuat semuanya dulu
         * membuat server kehabisan memori (Shopee: 198 MB, 31 dtk).
         */
        searchDocuments() {
            if (!this.customerId) return;
            clearTimeout(this.docSearchTimer);
            this.docSearchTimer = setTimeout(async () => {
                const seq = ++this.docSearchSeq;
                this.searchingDocs = true;
                try {
                    const base = this.returnType === 'invoice'
                        ? `{{ route('sales.ajax.returns.invoices') }}`
                        : `{{ route('sales.ajax.returns.orders') }}`;
                    const res  = await fetch(`${base}?customer_id=${this.customerId}&q=${encodeURIComponent(this.docQuery.trim())}`);
                    const list = await res.json();
                    if (seq !== this.docSearchSeq) return; // sudah ada ketikan yang lebih baru

                    const dipakai = this.documents.find(d => d.id == this.selectedDocId);
                    if (dipakai && !list.some(d => d.id == dipakai.id)) list.unshift(dipakai);

                    this.documents = list;
                    this.fullDocsLoaded = true;
                } catch (e) {
                    console.error('Gagal mencari dokumen:', e);
                } finally {
                    if (seq === this.docSearchSeq) this.searchingDocs = false;
                }
            }, 300);
        },

        // ── Customer Changed ──────────────────────────────
        async onCustomerChange(id) {
            this.customerId = id || null;
            this.fullDocsLoaded = false; // ganti pelanggan → daftar lama tak berlaku
            this.documents = [];
            this.selectedDoc = null;
            this.selectedDocId = null;
            this.items = [];
            this.customerBalance = 0;
            this.summary = {
                net: 0,
                reversed: 0,
                cogs: 0,
                conditionTotals: {
                    good: 0,
                    repair: 0,
                    damaged: 0,
                },
            };
            this.journalPreview = { conditions: [] };
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

        /**
         * Sisa dari masa ketika CS boleh berpindah antara "Dari Faktur" dan "Dari Sales
         * Order". Pemilihnya sudah dibuang, tapi fungsinya dibiarkan hidup karena ia satu-
         * satunya jalan memuat ulang daftar dokumen saat sumbernya berganti — dipakai lagi
         * kalau suatu saat retur atas SO perlu dibuka kembali.
         */
        async onReturnTypeChange() {
            if (!this.customerId) return;

            this.loadingDocs = true;
            this.fullDocsLoaded = false; // ganti sumber dokumen → daftar lama tak berlaku
            this.documents = [];
            this.selectedDoc = null;
            this.selectedDocId = null;
            this.items = [];
            this.summary = {
                net: 0,
                reversed: 0,
                cogs: 0,
                conditionTotals: {
                    good: 0,
                    repair: 0,
                    damaged: 0,
                },
            };
            this.journalPreview = { conditions: [] };
            this.docQuery = '';

            try {
                const url = this.returnType === 'invoice' 
                    ? `{{ route('sales.ajax.returns.invoices') }}?customer_id=${this.customerId}`
                    : `{{ route('sales.ajax.returns.orders') }}?customer_id=${this.customerId}`;
                
                const res = await fetch(url);
                this.documents = await res.json();
                this.fullDocsLoaded = true; // ini memang daftar penuh
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
                reversed: 0,
                cogs: 0,
                conditionTotals: {
                    good: 0,
                    repair: 0,
                    damaged: 0,
                },
            };
            this.journalPreview = { conditions: [] };

            if (!this.selectedDoc) return;

            // Map doc items to returnable items
            this.items = this.selectedDoc.items.map(item => this.buildItem(item));

            this.calculateSummary(); // 🔥 TRIGGER INITIAL CALCULATION
            // Dokumen baru → bawaan dihitung ulang dari nol, termasuk blok yang sempat diubah.
            this.blok.pembalikan.diubah = false;
            this.blok.penyelesaian.diubah = false;
            this.muatBawaan('semua');
        },

        // ── Qty Validation ──────────────────────────────
        validateQty(index) {
            const item = this.items[index];

            if (item.split) {
                // Mode pisah: jumlah semua kondisi tidak boleh melebihi qty dokumen.
                const total = this.itemTotalQty(item);
                item.qty_error = total > item.qty + 1e-9 ? `Σ maks ${item.qty}` : null;
                return;
            }

            const qty = parseFloat(item.qty_return) || 0;
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

        gantiJenis() {
            this.returnCase = Object.keys(KASUS[this.caseType] || {})[0] || '';
            this.gantiKasus();
        },

        gantiKasus() {
            // Refund sebagian hanya milik K4 — kasus lain kembali ke nilai penuh bawaan.
            if (this.returnCase !== 'K4') { this.dibalikManual = null; this.dibalikInput = ''; }
            this.applyDefaultConditionFromCaseType();
            this.muatBawaan();
        },

        /**
         * Kasus yang barangnya pasti tak sampai ke kita (paket hilang, refund tanpa barang
         * kembali, barang tetap di pembeli) → semua baris "Tidak Kembali". Kasus lain SENGAJA
         * tidak diisi: barang yang datang belum tentu utuh, kondisinya wajib hasil cek paket.
         * Baris yang kondisinya sudah dipisah manual TIDAK diutak-atik.
         */
        applyDefaultConditionFromCaseType() {
            if (!KASUS_TIDAK_KEMBALI.includes(this.returnCase)) return;
            const bawaan = 'tidak_kembali';

            this.items.forEach(item => {
                if (item.split) return;
                if (item.is_bundle) {
                    Object.keys(item.component_conditions || {}).forEach(pid => {
                        item.component_conditions[pid] = bawaan;
                    });
                    return;
                }
                item.condition = bawaan;
            });
            this.calculateSummary();
        },

        penjelasanKasus() {
            if (!this.returnCase) return 'Pilih kasusnya — isi bawaan jurnal & panduan di kanan mengikuti kasus ini.';
            const k = PANDUAN.find(x => x.key === this.returnCase);
            const tambahan = this.returnCase === 'K4' ? ' Isi "Nilai dibalik / refund" di blok Pembalikan.' : '';
            return k ? `${k.kapan} 💰 ${k.uang} 📦 ${k.barang}${tambahan}` : '';
        },

        onSplitChange(index) {
            this.validateQty(index);
            this.calculateSummary();
        },

        // Aktifkan pisah kondisi: bawa qty simpel yang sudah diisi sebagai nilai awal
        // pada kondisi terpilih agar tidak hilang.
        enableSplit(index) {
            const item = this.items[index];
            item.splits = { good: 0, repair: 0, damaged: 0, tidak_kembali: 0 };
            const qty = parseFloat(item.qty_return) || 0;
            if (qty > 0 && item.splits[item.condition] !== undefined) {
                item.splits[item.condition] = qty;
            }
            item.split = true;
            this.validateQty(index);
            this.calculateSummary();
        },

        // Batalkan pisah: kembali ke 1 qty + 1 kondisi (ambil kondisi non-nol pertama).
        disableSplit(index) {
            const item = this.items[index];
            const allocs = this.itemAllocations(item);
            item.qty_return = allocs.reduce((s, a) => s + a.qty, 0);
            item.condition  = allocs.length ? allocs[0].condition : 'good';
            item.splits     = { good: 0, repair: 0, damaged: 0, tidak_kembali: 0 };
            item.split      = false;
            this.validateQty(index);
            this.calculateSummary();
        },

        // Bangun 1 baris item retur dari item dokumen (+ data tersimpan saat edit).
        // Item bundle → mode kondisi PER KOMPONEN (tiap komponen punya kondisi sendiri).
        buildItem(docItem, saved = null) {
            const components = Array.isArray(docItem.components) ? docItem.components : [];
            const isBundle = components.length > 0;
            const cc = {};
            components.forEach(c => {
                const lama = (saved && saved.component_conditions && saved.component_conditions[c.product_id]) || 'good';
                cc[c.product_id] = ['hilang', 'tetap'].includes(lama) ? 'tidak_kembali' : lama;
            });
            return {
                id:              docItem.id,
                name:            docItem.name,
                qty:             docItem.qty,
                qty_return:      saved ? (parseFloat(saved.qty) || 0) : 0,
                unit_price:      docItem.unit_price,
                discount_amount: docItem.discount_amount,
                subtotal:        docItem.subtotal,
                cogs_total:      docItem.cogs_total,
                condition:       saved ? (saved.condition || 'good') : 'good',
                split:           saved ? !!saved.split : false,
                splits:          (saved && saved.splits) ? saved.splits : { good: 0, repair: 0, damaged: 0, tidak_kembali: 0 },
                is_bundle:       isBundle,
                components:      components,
                component_conditions: cc,
                qty_error:       null,
            };
        },

        // ── Alokasi (normalisasi simpel vs pisah) ──────────
        // Kembalikan daftar {condition, qty} untuk 1 produk NON-bundle:
        //  • mode simpel → 1 alokasi (qty_return + condition)
        //  • mode pisah  → sampai 3 alokasi (splits per kondisi, yang qty>0)
        // Bundle ditangani terpisah (qty di level bundle, COGS di-split per komponen).
        itemAllocations(item) {
            if (item.is_bundle) return [];
            if (item.split) {
                return ['good', 'repair', 'damaged', 'tidak_kembali']
                    .map(c => ({ condition: c, qty: parseFloat(item.splits?.[c]) || 0 }))
                    .filter(a => a.qty > 0);
            }
            const qty = parseFloat(item.qty_return) || 0;
            return qty > 0 ? [{ condition: item.condition, qty }] : [];
        },

        itemTotalQty(item) {
            if (item.is_bundle) return parseFloat(item.qty_return) || 0;
            return this.itemAllocations(item).reduce((s, a) => s + a.qty, 0);
        },

        // Bagi COGS bundle ke tiap komponen (bobot cogs komponen) dengan kondisi masing-masing.
        // Return [{condition, cogs}] untuk qty_return sekarang.
        bundleComponentCogs(item) {
            const q = parseFloat(item.qty_return) || 0;
            if (!item.is_bundle || q <= 0 || !item.qty) return [];
            const ratio = q / item.qty; // porsi qty yang diretur
            return item.components.map(c => ({
                condition: item.component_conditions[c.product_id] || 'good',
                cogs: (parseFloat(c.cogs_total) || 0) * ratio,
            }));
        },

        // Daftar datar untuk dikirim sebagai items[]. Non-bundle: 1 baris per alokasi kondisi.
        // Bundle: 1 baris + component_conditions per komponen.
        flatItems() {
            const out = [];
            this.items.forEach(item => {
                if (item.is_bundle) {
                    const q = parseFloat(item.qty_return) || 0;
                    if (q > 0) {
                        out.push({
                            invoice_item_id: item.id, qty: q, condition: 'good',
                            component_conditions: { ...item.component_conditions },
                        });
                    }
                    return;
                }
                this.itemAllocations(item).forEach(a => {
                    out.push({ invoice_item_id: item.id, qty: a.qty, condition: a.condition, component_conditions: {} });
                });
            });
            return out;
        },

        // ── Calculate ──────────────────────────────────
        lineValueFor(item, qty) {
            if (!qty || !item.qty) return 0;
            return Math.round(item.subtotal * (qty / item.qty) * 100) / 100;
        },

        lineCogsFor(item, qty) {
            const totalQty  = parseFloat(item.qty) || 0;
            const totalCogs = parseFloat(item.cogs_total) || 0;
            if (!qty || !totalQty || !totalCogs) return 0;
            return (totalCogs / totalQty) * qty;
        },

        // Nilai retur baris (gabungan semua alokasi produk) — untuk kolom "Nilai Retur"
        getLineValue(item) {
            return this.lineValueFor(item, this.itemTotalQty(item));
        },

        calculateSummary() {
            // Kondisi barang HANYA menentukan jurnal HPP (blok a). Penjualan dibalik atau tidak
            // diputuskan server dari Jenis Retur + Hasil Banding (blok b & c).
            let net = 0;
            let cogs = 0;
            const conditionTotals = { good: 0, repair: 0, damaged: 0, tidak_kembali: 0 };
            const tambah = (kondisi, nilai) => {
                const k = ['hilang', 'tetap'].includes(kondisi) ? 'tidak_kembali' : kondisi;
                cogs += nilai;
                if (conditionTotals[k] !== undefined) conditionTotals[k] += nilai;
            };

            this.items.forEach(item => {
                if (item.is_bundle) {
                    const q = parseFloat(item.qty_return) || 0;
                    if (q <= 0) return;
                    net += this.lineValueFor(item, q);
                    this.bundleComponentCogs(item).forEach(cc => tambah(cc.condition, cc.cogs));
                    return;
                }
                this.itemAllocations(item).forEach(a => {
                    net += this.lineValueFor(item, a.qty);
                    tambah(a.condition, this.lineCogsFor(item, a.qty));
                });
            });

            const bulat = v => Math.round(v * 100) / 100;
            this.summary = {
                net: bulat(net),
                cogs: bulat(cogs),
                conditionTotals: Object.fromEntries(Object.entries(conditionTotals).map(([k, v]) => [k, bulat(v)])),
            };

            this.journalPreview = this.buildJournalPreview();
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
                tidak_kembali: {
                    label: 'Beban Kerugian Retur',
                    debitLabel: 'Dr. 6105 Beban Kerugian Retur (barang tidak kembali)',
                    creditLabel: 'Cr. 5001 HPP',
                    debitClass: 'text-red-600',
                    tone: 'red',
                },
            };

            return meta[condition] || meta.good;
        },

        // ── Panduan jurnal (kolom kanan) ───────────────
        /** Kondisi semua baris yang diretur (bundle: kondisi per komponen). */
        kondisiDipakai() {
            const out = [];
            this.items.forEach(item => {
                if (item.is_bundle) {
                    if ((parseFloat(item.qty_return) || 0) > 0) out.push(...Object.values(item.component_conditions || {}));
                    return;
                }
                this.itemAllocations(item).forEach(a => out.push(a.condition));
            });
            return out;
        },

        /** Kasus yang sedang dikerjakan — kunci PANDUAN. */
        kasusAktif() {
            if (!this.selectedDoc || this.returnType !== 'invoice') return null;
            if (!this.selectedDoc.is_marketplace) return 'K7';
            // Kasus pilihan admin tetap jadi "KASUS INI"; dana yang sudah cair (K5) hanya
            // penanda tambahan, bukan pengganti pilihannya.
            return this.returnCase || (this.danaSudahCair() ? 'K5' : null);
        },

        /** Dana pesanan sudah cair ke Saldo Penjualan (tak ada blok Penyelesaian) — terdeteksi dari data. */
        danaSudahCair() {
            return !!this.selectedDoc?.is_marketplace && this.returnType === 'invoice'
                && this.caseType === 'diajukan_konsumen' && this.blok.penyelesaian.rows === null && this.returnCase !== 'K6';
        },

        panduanGrup() {
            const grup = [];
            PANDUAN.forEach(k => {
                let g = grup.find(x => x.nama === k.grup);
                if (!g) grup.push(g = { nama: k.grup, kasus: [] });
                g.kasus.push(k);
            });
            // Grup kasus yang sedang dikerjakan tampil paling atas.
            const aktif = PANDUAN.find(k => k.key === this.kasusAktif());
            return aktif ? [...grup.filter(g => g.nama === aktif.grup), ...grup.filter(g => g.nama !== aktif.grup)] : grup;
        },

        panduanTerbuka(key) {
            return this.panduanBuka === null
                ? key === this.kasusAktif() || (key === 'K5' && this.danaSudahCair())
                : this.panduanBuka === key;
        },

        /** Isian yang bertentangan dengan jenis kasusnya. */
        peringatanKasus() {
            if (!this.selectedDoc) return [];
            const w = [];
            const k = this.kondisiDipakai();
            if (!this.caseType) w.push('Jenis Retur belum dipilih — retur tak bisa diselesaikan.');
            else if (!this.returnCase) w.push('Kasus belum dipilih.');
            if (KASUS_TIDAK_KEMBALI.includes(this.returnCase) && k.some(c => c !== 'tidak_kembali')) {
                w.push('Kasus ini barangnya tidak sampai ke kita, tapi ada baris yang kondisinya bukan "Tidak Kembali" — periksa lagi (kondisi hanya memengaruhi jurnal HPP).');
            }
            if (this.caseType === 'gagal_kirim' && k.includes('tidak_kembali')) {
                w.push('Gagal Kirim berarti paketnya kembali — isi kondisi hasil cek paket (Utuh / Perbaikan / Rusak).');
            }
            if (this.returnCase === 'K4' && this.dibalikManual === null) {
                w.push('Refund sebagian: isi "Nilai dibalik / refund" di blok Pembalikan sesuai nilai yang dikembalikan ke pembeli.');
            }
            return w;
        },

        // ── Jurnal tiga blok ───────────────────────────
        /**
         * Minta isi bawaan ke server (SalesReturnService::jurnalBawaan — satu sumber hitungan
         * dengan posting). Blok yang sudah diubah admin TIDAK ditimpa, kecuali diminta
         * (`paksa` = nama blok, atau 'semua').
         */
        muatBawaan(paksa = null) {
            if (this.returnType !== 'invoice' || !this.selectedDocId) return;
            clearTimeout(this.bawaanTimer);
            this.bawaanTimer = setTimeout(async () => {
                const seq = ++this.bawaanSeq;
                this.memuatBawaan = true;
                try {
                    const res = await fetch(URL_JURNAL_BAWAAN, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('#returnForm input[name=_token]')?.value || '',
                        },
                        body: JSON.stringify({
                            invoice_id: this.selectedDocId,
                            items: this.flatItems(),
                            return_type: this.caseType || null,
                            appeal_result: this.appealResult || null,
                            refund_target: this.refundTarget || null,
                            refund_account_id: this.refundAccountId || null,
                            dibalik: this.dibalikManual,
                        }),
                    });
                    if (!res.ok || seq !== this.bawaanSeq) return;
                    const data = await res.json();
                    if (seq !== this.bawaanSeq) return;

                    this.bawaan = { tujuan: data.tujuan || {}, konteks: data.konteks || null, dibalik: data.dibalik || 0 };
                    if (this.dibalikManual === null) this.dibalikInput = data.dibalik > 0 ? window.formatThousands(Math.round(data.dibalik)) : '';
                    if (!this.refundTarget || !(this.refundTarget in this.bawaan.tujuan)) this.refundTarget = data.target || '';

                    ['pembalikan', 'penyelesaian'].forEach(k => {
                        (data[k] || []).forEach(r => { if (r.account_id) this.labelAkun[r.account_id] = `${r.code} · ${r.name}`; });
                        const b = this.blok[k];
                        if (paksa === k || paksa === 'semua' || !b.diubah) {
                            b.rows = barisForm(data[k]);
                            b.diubah = false;
                        }
                    });
                } catch (e) {
                    console.error('Gagal memuat jurnal bawaan:', e);
                } finally {
                    if (seq === this.bawaanSeq) this.memuatBawaan = false;
                }
            }, paksa ? 0 : 350);
        },

        isiUlangBlok(kunci) {
            if (this.blok[kunci].diubah && !confirm('Ganti isi blok ini dengan bawaan sistem?')) return;
            if (kunci === 'pembalikan') { this.dibalikManual = null; this.dibalikInput = ''; }
            this.muatBawaan(kunci);
        },

        /**
         * Refund hasil negosiasi (mis. barang tetap di pembeli, dikembalikan sebagian): satu
         * angka ini menyusun ulang blok Pembalikan, dan blok Penyelesaian ikut mencairkan
         * sisanya — bukan empat baris yang diketik ulang satu per satu.
         */
        aturDibalik() {
            const v = this.dibalikInput === '' ? null : this.angka(this.dibalikInput);
            this.dibalikManual = v;
            this.muatBawaan('pembalikan');
        },

        /** Admin menyentuh blok: kunci dari bawaan, lalu seimbangkan baris penyeimbang (⚖). */
        ubahBlok(kunci) {
            this.blok[kunci].diubah = true;
            this.$nextTick(() => this.seimbangkan(kunci));
        },

        seimbangkan(kunci) {
            const rows = this.blok[kunci].rows || [];
            const auto = rows.find(r => r.auto);
            if (!auto) return;
            let d = 0, k = 0;
            rows.forEach(r => { if (r !== auto) { d += this.angka(r.debit); k += this.angka(r.credit); } });
            const selisih = Math.round((k - d) * 100) / 100;
            auto.debit  = selisih > 0 ? window.formatThousands(Math.round(selisih)) : '';
            auto.credit = selisih < 0 ? window.formatThousands(Math.round(-selisih)) : '';
        },

        /** Blok dikirim ke server? Draft hanya menyimpan blok yang diubah. */
        kirimBlok(kunci) {
            const b = this.blok[kunci];
            return b.rows !== null && (this.formStatus !== 'draft' || b.diubah);
        },

        angka(v) { return window.cleanNumber(v); },

        /**
         * Kelompok sebuah baris — murni tampilan, dari akunnya:
         *   Pembalikan   : pembatalan penjualan (Retur / Piutang / tujuan dana) · pengembalian titipan (Uang Muka / Saldo Ditahan)
         *   Penyelesaian : pelunasan faktur (Uang Muka / Piutang) · pencairan dana (sisanya)
         */
        grupBaris(kunci, b) {
            const A = this.selectedDoc?.akun || {};
            const id = String(b?.account_id || '');
            const di = (...akun) => akun.some(a => a && String(a.id) === id);
            if (kunci === 'pembalikan') return di(A.uang_muka, A.ditahan) ? 'titipan' : 'batal';
            return di(A.uang_muka, A.piutang) ? 'pelunasan' : 'pencairan';
        },

        grupInfo(kunci, b) {
            return {
                batal:     { judul: 'Pembatalan penjualan', ket: 'penjualan dibalik & tagihan faktur dihapus', warna: 'border-blue-300' },
                titipan:   { judul: 'Pengembalian titipan', ket: 'uang pembeli yang ditahan marketplace dikembalikan ke pembeli', warna: 'border-sky-300' },
                pelunasan: { judul: 'Pelunasan faktur', ket: 'titipan pembeli melunasi tagihan — tanpa uang bergerak', warna: 'border-violet-300' },
                pencairan: { judul: 'Pencairan dana', ket: 'Saldo Ditahan → Saldo Penjualan, dipotong admin / pajak', warna: 'border-emerald-300' },
            }[this.grupBaris(kunci, b)];
        },

        totalBlok(kunci, sisi) {
            return (this.blok[kunci].rows || []).reduce((t, r) => t + (r.account_id ? this.angka(r[sisi]) : 0), 0);
        },

        blokSeimbang(kunci) {
            return Math.abs(this.totalBlok(kunci, 'debit') - this.totalBlok(kunci, 'credit')) < 0.5;
        },

        /** Mutasi (debit − kredit) sebuah akun di blok-blok yang disebut. */
        mutasi(akunId, bloks = ['pembalikan', 'penyelesaian']) {
            if (!akunId) return 0;
            return bloks.reduce((t, k) => t + (this.blok[k].rows || [])
                .filter(r => String(r.account_id) === String(akunId))
                .reduce((s, r) => s + this.angka(r.debit) - this.angka(r.credit), 0), 0);
        },

        kodeAkun(id) {
            return (this.akunLabel(id).split(' · ')[0] || '').trim();
        },

        /** Penjualan yang dibalik = mutasi akun pendapatan (kode 4…) di blok Pembalikan. */
        nilaiDibalik() {
            return (this.blok.pembalikan.rows || [])
                .filter(r => r.account_id && this.kodeAkun(r.account_id).startsWith('4'))
                .reduce((s, r) => s + this.angka(r.debit) - this.angka(r.credit), 0);
        },

        danaCair() {
            return this.mutasi(this.selectedDoc?.akun?.dompet?.id, ['penyelesaian']);
        },

        /** Beban yang dipotong marketplace di blok Penyelesaian (admin, pajak, …). */
        potonganMarketplace() {
            return (this.blok.penyelesaian.rows || [])
                .filter(r => r.account_id && /^[56]/.test(this.kodeAkun(r.account_id)))
                .reduce((s, r) => s + this.angka(r.debit) - this.angka(r.credit), 0);
        },

        /** Saldo pesanan sebelum → sesudah retur ini (piutang, uang muka, saldo ditahan). */
        cekSaldo() {
            const K = this.bawaan.konteks;
            if (!K || this.returnType !== 'invoice') return [];
            const out = [{ label: 'Piutang faktur', awal: K.sisa_tagihan, sisa: K.sisa_tagihan + this.mutasi(K.akun.piutang) }];
            if (K.akun.ditahan) {
                out.push({ label: 'Uang Muka pembeli', awal: K.uang_muka, sisa: K.uang_muka - this.mutasi(K.akun.uang_muka) });
                out.push({ label: 'Saldo Ditahan', awal: K.sisa_ditahan, sisa: K.sisa_ditahan + this.mutasi(K.akun.ditahan) });
            }
            return out.map(c => ({ ...c, sisa: Math.round(c.sisa) }));
        },

        akunLabel(id) {
            if (!id) return '';
            const a = AKUN_JURNAL.find(x => String(x.id) === String(id))
                || Object.values(this.selectedDoc?.akun || {}).find(x => x && String(x.id) === String(id));
            return a ? `${a.code} · ${a.name}` : (this.labelAkun[id] || '');
        },

        // Semua kata yang diketik harus muncul di kode+nama akun, urutan bebas:
        // "retur jual" menemukan "4004 Retur Penjualan".
        akunCocok(cari) {
            const kata = String(cari || '').toLowerCase().split(/\s+/).filter(Boolean);
            if (!kata.length) return AKUN_JURNAL;
            return AKUN_JURNAL.filter(a => {
                const teks = `${a.code} ${a.name}`.toLowerCase();
                return kata.every(k => teks.includes(k));
            });
        },

        buildJournalPreview() {
            const preview = {
                conditions: [],
            };

            // Hanya blok HPP (a). Blok Pembalikan & Penyelesaian datang dari server lewat
            // muatBawaan() — satu sumber hitungan dengan posting.
            ['good', 'repair', 'damaged', 'tidak_kembali'].forEach(condition => {
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
            const hasItem = this.items.some(i => this.itemTotalQty(i) > 0);
            const hasError = this.items.some(i => i.qty_error);
            // Jurnal timpang & saldo pesanan yang jadi minus ditahan di sini, bukan dibiarkan
            // memantul dari server.
            if (this.returnType === 'invoice') {
                if (this.memuatBawaan) return false;
                if (['pembalikan', 'penyelesaian'].some(k => this.blok[k].rows !== null && !this.blokSeimbang(k))) return false;
                if (this.cekSaldo().some(c => c.sisa < -1)) return false;
            }
            return hasItem && !hasError && this.summary.net > 0;
        },

        // ── Submit Logic ───────────────────────────────
        
        /** Ajukan Banding / Kembalikan ke Retur Baru — isian draft ikut tersimpan. */
        simpanLaluPindah(tahap) {
            if (!this.canSubmit()) {
                alert('Isian belum lengkap (pelanggan, faktur, tanggal, item) — lengkapi dulu sebelum memindahkan tahap.');
                return;
            }
            this.pindahTahap = tahap;
            this.saveDraft();
        },

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
        // Nomor pesanan inti ala MarketplaceSettlementService::normalizeOrderRef():
        // "SP-260909JCMTPRG2" → "260909JCMTPRG2", "TT-5862...680-127338" → "5862...680".
        // Faktur non-marketplace (SI/2026/09/...) tak berprefix → '' (tombol disembunyikan).
        nomorPesananInti(nomor) {
            const ref = String(nomor || '').trim();
            if (!/^[A-Za-z]{2,4}-/.test(ref)) return '';
            return ref.replace(/^[A-Za-z]{2,4}-/, '').split('-')[0].trim();
        },

        salinNomorPesanan() {
            const teks = this.nomorPesananInti(this.selectedDoc?.number);
            if (!teks) return;
            const selesai = () => {
                this.nomorTersalin = true;
                setTimeout(() => this.nomorTersalin = false, 1200);
            };
            // clipboard API hanya hidup di konteks aman (https/localhost) — server via IP http pakai cadangan.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(teks).then(selesai).catch(() => {});
                return;
            }
            const ta = document.createElement('textarea');
            ta.value = teks; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); selesai(); } catch (_) {}
            document.body.removeChild(ta);
        },

        formatNumber(n) {
            if (!n && n !== 0) return '0';
            return Math.round(n).toLocaleString('id-ID');
        },

        getCustomerName() {
            return this.customerName;
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
