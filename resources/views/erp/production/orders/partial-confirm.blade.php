@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Selesaikan Sebagian</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                <a href="{{ route('production.orders.show', $order->id) }}" class="font-bold text-blue-600 hover:underline">{{ $order->order_number }}</a>
                · Ambil hasil yang sudah jadi — produksi sisa unit tetap berjalan
            </p>
        </div>
        <a href="{{ route('production.process.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            ← Kembali ke Proses
        </a>
    </div>

    <form action="{{ route('production.process.partial', $order->id) }}" method="POST">
        @csrf

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

            {{-- Output yang diambil sekarang --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-bold text-gray-800">Hasil yang Diambil Sekarang</h2>
                    <span class="text-[10px] font-bold bg-blue-100 text-blue-700 border border-blue-200 px-2 py-0.5 rounded uppercase tracking-widest">
                        Timer tetap jalan
                    </span>
                </div>

                @php $mainOutputs = $order->outputs->where('output_type', '!=', 'by_product'); @endphp

                @if($mainOutputs->isEmpty())
                    <p class="text-xs text-gray-400 text-center py-6">Order ini tidak punya baris produk utama.</p>
                @endif

                <div class="space-y-3">
                    @foreach($mainOutputs as $i => $out)
                        @php
                            $released  = (float) ($releasedQty[$out->id] ?? 0);
                            $target    = (float) $out->qty_planned;
                            $sisaQty   = max(0, $target - $released);
                            $savedAlloc = null;
                            $defWh     = (int) ($defaultWarehouseId ?: $order->warehouse_id);
                        @endphp
                        <input type="hidden" name="outputs[{{ $i }}][output_id]" value="{{ $out->id }}">

                        <div class="border border-gray-100 rounded-xl p-4 bg-gray-50/50"
                             x-data="{
                                 qty: {{ $sisaQty > 0 ? 0 : 0 }},
                                 sisa: {{ $sisaQty }},
                                 whs: {{ Js::from($warehouses) }},
                                 defWh: {{ $defWh }},
                                 allocations: [],
                                 init() { this.allocations = [{ warehouse_id: this.defWh, qty: this.qty }]; },
                                 sum() { return Math.round(this.allocations.reduce((s, a) => s + (parseFloat(a.qty) || 0), 0) * 10000) / 10000; },
                                 balanced() { return Math.abs(this.sum() - (parseFloat(this.qty) || 0)) < 1e-6; },
                                 syncSingle() { if (this.allocations.length === 1) this.allocations[0].qty = parseFloat(this.qty) || 0; },
                                 add() {
                                     const used = new Set(this.allocations.map(a => a.warehouse_id));
                                     const next = this.whs.find(w => !used.has(w.id));
                                     this.allocations.push({ warehouse_id: next ? next.id : this.defWh, qty: 0 });
                                 },
                                 remove(j) { this.allocations.splice(j, 1); if (this.allocations.length === 1) this.allocations[0].qty = parseFloat(this.qty) || 0; },
                             }">
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                <span class="font-bold text-gray-800 text-sm">{{ $out->product?->name ?? '—' }}</span>
                                <span class="text-[10px] bg-green-100 text-green-700 px-1.5 py-0.5 rounded font-black">UTAMA</span>
                                @if($out->product?->sku)
                                    <span class="text-xs text-gray-400">{{ $out->product->sku }}</span>
                                @endif
                            </div>

                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">Target</label>
                                    <div class="text-sm font-bold text-gray-600 bg-gray-100 rounded-lg px-3 py-2 text-right">
                                        {{ number_format($target, 2) }}
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">Sudah Diambil</label>
                                    <div class="text-sm font-bold text-gray-600 bg-gray-100 rounded-lg px-3 py-2 text-right">
                                        {{ number_format($released, 2) }}
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">Ambil Sekarang *</label>
                                    <input type="number"
                                           name="outputs[{{ $i }}][qty_produced]"
                                           x-model.number="qty" @input="syncSingle()"
                                           step="0.01" min="0" required
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white text-right">
                                    <p class="text-[10px] text-gray-400 mt-1 text-right">
                                        Sisa target: <b>{{ rtrim(rtrim(number_format($sisaQty, 2, ',', '.'), '0'), ',') }}</b>
                                    </p>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="block text-[10px] font-bold text-gray-500 mb-1">
                                    Keterangan
                                    <span class="font-normal text-gray-400">(audit — alasan pengambilan sebagian, mis. batas kirim marketplace)</span>
                                </label>
                                <input type="text"
                                       name="outputs[{{ $i }}][variance_notes]"
                                       placeholder="cth: kejar batas kirim Shopee, sisa menyusul..."
                                       class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white text-gray-700">
                            </div>

                            {{-- Alokasi Gudang --}}
                            <div class="mt-3 pt-3 border-t border-dashed border-gray-200" x-show="whs.length > 1">
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-[10px] font-bold text-gray-500">Alokasi Gudang</label>
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded"
                                          :class="balanced() ? 'text-green-700 bg-green-50' : 'text-red-600 bg-red-50'">
                                        <span x-text="Number(sum()).toLocaleString('id-ID', {maximumFractionDigits: 2})"></span>
                                        /
                                        <span x-text="Number(qty || 0).toLocaleString('id-ID', {maximumFractionDigits: 2})"></span>
                                    </span>
                                </div>
                                <div class="space-y-1.5">
                                    <template x-for="(al, j) in allocations" :key="j">
                                        <div class="flex gap-1.5 items-center">
                                            <select x-model.number="al.warehouse_id"
                                                    :name="`outputs[{{ $i }}][allocations][${j}][warehouse_id]`"
                                                    class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-xs font-semibold bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                @foreach($warehouses as $w)
                                                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                                                @endforeach
                                            </select>
                                            <input type="number" step="0.01" min="0"
                                                   x-model.number="al.qty"
                                                   :name="`outputs[{{ $i }}][allocations][${j}][qty]`"
                                                   class="w-24 border border-gray-200 rounded-lg px-2 py-1.5 text-xs font-bold text-right bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
                                            <button type="button" @click="remove(j)" x-show="allocations.length > 1"
                                                    class="w-7 h-7 flex-shrink-0 rounded-lg bg-gray-100 hover:bg-red-100 text-gray-500 hover:text-red-600 text-xs">✕</button>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" @click="add()" x-show="allocations.length < whs.length"
                                        class="mt-1.5 text-[11px] font-bold text-blue-700 hover:text-blue-800">+ Tambah Gudang</button>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($order->outputs->where('output_type', 'by_product')->isNotEmpty())
                    <div class="mt-4 bg-gray-50 border border-gray-200 text-gray-600 text-xs rounded-lg px-3 py-2">
                        <b>Produk sampingan tidak diisi di sini.</b> Jatah biayanya disisihkan otomatis dan baru dicatat
                        saat finalisasi penutup, karena persentasenya baru pasti setelah seluruh produksi selesai.
                    </div>
                @endif
            </div>

            {{-- Ringkasan biaya & riwayat --}}
            <div class="space-y-5">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h2 class="font-bold text-gray-800 mb-4">Biaya Produksi (WIP)</h2>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">WIP keseluruhan order</span>
                            <span class="font-bold text-gray-800">{{ rupiah($wip['total']) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Sudah dilepas ke stok</span>
                            <span class="font-bold text-gray-600">{{ rupiah($wip['released']) }}</span>
                        </div>
                        @if($wip['reserved_byproduct'] > 0)
                            <div class="flex justify-between">
                                <span class="text-gray-500">Dicadangkan produk sampingan</span>
                                <span class="font-bold text-gray-600">{{ rupiah($wip['reserved_byproduct']) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between pt-2 border-t border-gray-100">
                            <span class="font-black text-gray-600 uppercase text-xs">Tersedia untuk produk utama</span>
                            <span class="font-black text-indigo-700">{{ rupiah($wip['main_pool']) }}</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-3 leading-relaxed">
                        Biaya batch ini = sisa WIP untuk produk utama × qty diambil ÷ sisa target. Biaya yang masuk
                        belakangan (mis. Penambahan Bahan) otomatis hanya membebani unit yang belum keluar.
                    </p>
                </div>

                @if($batches->isNotEmpty())
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                        <h2 class="font-bold text-gray-800 mb-3">Riwayat Pengambilan</h2>
                        <div class="space-y-2">
                            @foreach($batches as $b)
                                <div class="flex items-center justify-between text-xs border border-gray-100 rounded-lg px-3 py-2 {{ $b->voided_at ? 'opacity-50 line-through' : 'bg-gray-50/50' }}">
                                    <div>
                                        <span class="font-bold text-gray-700">{{ $b->label() }}</span>
                                        <span class="text-gray-400 ml-2">{{ $b->created_at?->format('d/m/Y H:i') }}</span>
                                        <div class="text-gray-500 mt-0.5">
                                            {{ $b->items->map(fn($it) => rtrim(rtrim(number_format($it->qty, 2, ',', '.'), '0'), ',') . ' × ' . ($it->product?->name ?? '—'))->join(', ') }}
                                        </div>
                                    </div>
                                    <span class="font-bold text-gray-700 flex-shrink-0 ml-3">{{ rupiah($b->wip_released) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 text-xs text-amber-800">
                    <div class="font-bold mb-1">Saat menyelesaikan sebagian:</div>
                    <ul class="list-disc list-inside space-y-1">
                        <li>Langkah dan timer <b>tidak disentuh</b> — produksi sisa unit lanjut seperti biasa.</li>
                        <li>Order pindah ke status <b>Selesai Sebagian</b> dan tetap ada di papan proses.</li>
                        <li>Kalau qty yang keluar ternyata menyimpang dari rencana, perbaiki dulu lewat <b>Revisi Target</b> di halaman order.</li>
                        <li>Bahan tambahan yang benar-benar keluar gudang dicatat lewat <b>Penambahan Bahan</b>, bukan lewat target.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-6 flex items-center gap-3">
            <button type="submit"
                    onclick="return confirm('Ambil sebagian hasil produksi ke stok sekarang?')"
                    class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                ✓ Ambil Sebagian ke Stok
            </button>
            <a href="{{ route('production.process.index') }}"
               class="px-5 py-3 border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-semibold rounded-xl transition">
                Batal
            </a>
        </div>

    </form>

</div>
@endsection
