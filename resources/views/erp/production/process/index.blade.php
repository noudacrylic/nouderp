@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4"
     x-data="{
         detail: null,
         selesai: null,
         mulai: null,
         catatan: null,
         openSelesai(d) {
             // Inisialisasi qty aktual = rencana bila belum diisi, lalu hitung % awal.
             if (d && Array.isArray(d.outputs)) {
                 d.outputs.forEach(o => {
                     o.qty_produced = (parseFloat(o.qty_produced) > 0) ? parseFloat(o.qty_produced) : parseFloat(o.qty_planned);
                 });
             }
             this.selesai = d;
             this.recalcSelesai();
         },
         // Hitung ulang persentase biaya per output dari qty hasil produksi terbaru:
         // sampingan = unit% × (qty/siklus), utama = sisa (100 − Σ). Kerusakan sampingan
         // (qty turun) otomatis menambah beban produk utama.
         recalcSelesai() {
             if (!this.selesai || !Array.isArray(this.selesai.outputs)) return;
             const cyc = parseFloat(this.selesai.planned_cycles) || 1;
             let sumBp = 0;
             this.selesai.outputs.forEach(o => {
                 if (o.output_type === 'by_product') {
                     const up  = parseFloat(o.unit_percentage) || 0;
                     const qty = parseFloat(o.qty_produced) || 0;
                     o.calc_percentage = Math.round(up * (qty / cyc) * 100) / 100;
                     sumBp += o.calc_percentage;
                 }
             });
             this.selesai.outputs.forEach(o => {
                 if (o.output_type === 'main') o.calc_percentage = Math.round((100 - sumBp) * 100) / 100;
             });
         },
     }"
     @open-detail.window="detail = $event.detail"
     @open-selesai.window="openSelesai($event.detail)"
     @open-mulai.window="mulai = $event.detail"
     @open-catatan.window="catatan = $event.detail">

    {{-- Flash message (mis. gagal Mulai: belum scan check-in, di luar jam kerja, dll.) --}}

    {{-- Header --}}
    <div class="flex justify-between items-center mb-5">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Proses Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">
                @if($selectedDepartment)
                    Divisi: <strong>{{ $selectedDepartment->name }}</strong>
                @else
                    Semua Divisi
                @endif
            </p>
        </div>
        <a href="{{ route('production.orders.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">
            ← Order Produksi
        </a>
    </div>

    @if(!empty($testingMode))
        <div class="mb-5 flex items-center gap-2 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl px-4 py-2.5 text-sm font-semibold">
            🧪 <span>Mode Testing aktif — task bisa dimulai tanpa scan sidik jari. Matikan di <a href="{{ route('production.settings') }}" class="underline">Pengaturan Produksi</a> untuk produksi nyata.</span>
        </div>
    @endif


    {{-- Bulk bar penggabungan task (muncul saat ada kartu dipilih) --}}
    <div x-show="$store.merge.count > 0" x-cloak
         class="sticky top-0 z-30 mb-4 bg-white border border-indigo-200 rounded-xl px-4 py-2.5 shadow-sm flex items-center gap-3 flex-wrap">
        <span class="text-sm font-bold text-indigo-700"><span x-text="$store.merge.count"></span> task dipilih</span>
        <span x-show="$store.merge.mismatch" class="text-xs text-red-600 font-semibold">
            ⚠️ Komponen / langkah berbeda — tidak bisa digabung.
        </span>
        <span x-show="!$store.merge.mismatch && $store.merge.count >= 2" class="text-xs text-gray-500">
            Total qty gabungan: <strong x-text="$store.merge.combinedQty"></strong>
        </span>
        <div class="ml-auto flex items-center gap-2">
            <button type="button"
                    :disabled="!$store.merge.sigOk"
                    @click="$store.merge.confirmOpen = true"
                    :class="$store.merge.sigOk ? 'bg-indigo-600 hover:bg-indigo-700 cursor-pointer' : 'bg-indigo-300 cursor-not-allowed'"
                    class="text-xs px-4 py-1.5 rounded-lg text-white font-bold transition">🔗 Gabung</button>
            <button type="button" @click="$store.merge.clear()"
                    class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">Batal</button>
        </div>
    </div>

    {{-- Banner: urutan antrean berubah --}}
    <div id="queue-changed-banner"
         class="hidden mb-4 bg-amber-50 border border-amber-300 text-amber-800 px-5 py-3 rounded-xl text-sm font-medium flex items-center justify-between gap-4">
        <span>⚡ Urutan antrean berubah (skor produk diperbarui). Reload untuk melihat urutan terbaru.</span>
        <button onclick="window.location.reload()"
                class="flex-shrink-0 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold px-4 py-1.5 rounded-lg transition">
            Refresh Sekarang
        </button>
    </div>

    {{-- 3-Panel Task Manager Layout --}}
    <div style="display:grid; grid-template-columns:1fr 1fr; grid-template-rows:auto auto; gap:1rem; align-items:start;">

        {{-- PANEL KIRI: ANTRE --}}
        <div style="grid-row: span 2;" class="bg-white border border-amber-100 rounded-2xl shadow-sm flex flex-col" style="min-height:300px;">
            <div class="px-4 py-2.5 border-b border-amber-100 flex items-center gap-2 bg-amber-50 rounded-t-2xl">
                <span class="w-2 h-2 rounded-full bg-amber-400 flex-shrink-0"></span>
                <h2 class="font-black text-amber-700 text-xs tracking-widest uppercase">Antre</h2>
                <span class="ml-auto text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-black border border-amber-200">{{ $pendingSteps->count() }}</span>
            </div>
            <div class="flex-1 overflow-y-auto p-2.5 space-y-2">
                @forelse($pendingSteps as $step)
                    @include('erp.production.process._step-card', ['step' => $step, 'panel' => 'pending', 'queueNumber' => $loop->iteration, 'busyExecutorIds' => $busyExecutorIds])
                @empty
                    <div class="py-10 text-center"><p class="text-xs text-gray-400">Tidak ada task dalam antrean</p></div>
                @endforelse
            </div>
        </div>

        {{-- PANEL KANAN ATAS: SEDANG DIKERJAKAN --}}
        <div class="bg-white border border-indigo-100 rounded-2xl shadow-sm">
            <div class="px-4 py-2.5 border-b border-indigo-100 flex items-center gap-2 bg-indigo-50 rounded-t-2xl">
                <span class="w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0 animate-pulse"></span>
                <h2 class="font-black text-indigo-700 text-xs tracking-widest uppercase">Sedang Dikerjakan</h2>
                <span class="ml-auto text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-black border border-indigo-200">{{ $inProgressSteps->count() }}</span>
            </div>
            <div class="p-2.5 space-y-2">
                @forelse($inProgressSteps as $step)
                    @include('erp.production.process._step-card', ['step' => $step, 'panel' => 'in_progress'])
                @empty
                    <div class="py-8 text-center"><p class="text-xs text-gray-400">Tidak ada task aktif</p></div>
                @endforelse
            </div>
        </div>

        {{-- PANEL KANAN BAWAH: PENDING (PAUSED) --}}
        <div class="bg-white border border-orange-100 rounded-2xl shadow-sm">
            <div class="px-4 py-2.5 border-b border-orange-100 flex items-center gap-2 bg-orange-50 rounded-t-2xl">
                <span class="w-2 h-2 rounded-full bg-orange-400 flex-shrink-0"></span>
                <h2 class="font-black text-orange-700 text-xs tracking-widest uppercase">Pending</h2>
                <span class="ml-auto text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full font-black border border-orange-200">{{ $pausedSteps->count() }}</span>
            </div>
            <div class="p-2.5 space-y-2">
                @forelse($pausedSteps as $step)
                    @include('erp.production.process._step-card', ['step' => $step, 'panel' => 'paused'])
                @empty
                    <div class="py-8 text-center"><p class="text-xs text-gray-400">Tidak ada task yang dipending</p></div>
                @endforelse
            </div>
        </div>

    </div>

    {{-- ===== MODAL: MULAI ===== --}}
    <div x-show="mulai !== null"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
         @click.self="mulai = null"
         style="display:none;">

        <div x-show="mulai !== null"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden">

            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-gray-800 text-sm">Mulai Pengerjaan</h3>
                    <p class="text-xs text-gray-400 mt-0.5" x-text="mulai ? mulai.order_number + ' · ' + mulai.step_name : ''"></p>
                </div>
                <button type="button" @click="mulai = null"
                        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center text-sm">✕</button>
            </div>

            <form id="form-mulai" method="POST" class="p-5" :action="mulai ? mulai.action_url : '#'"
                  x-data="{ selected: [] }">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-600 mb-2">
                        Pilih Operator
                        <span class="text-gray-400 font-normal">(bisa lebih dari 1)</span>
                    </label>

                    {{-- Tidak ada executor --}}
                    <p x-show="!mulai || mulai.executors.length === 0"
                       class="text-xs text-gray-400 italic">Tidak ada operator terdaftar di divisi ini.</p>

                    {{-- Semua busy --}}
                    <p x-show="mulai && mulai.executors.length > 0 && mulai.executors.every(op => op.busy)"
                       class="text-xs text-red-500 font-medium mb-2">
                        Semua operator sedang mengerjakan task. Selesaikan atau pause task aktif terlebih dahulu.
                    </p>

                    <div class="space-y-1.5">
                        <template x-for="op in (mulai ? mulai.executors : [])" :key="op.id">
                            <label :class="op.busy ? 'opacity-50 cursor-not-allowed bg-gray-50' : 'cursor-pointer hover:bg-indigo-50'"
                                   class="flex items-center gap-3 px-3 py-2.5 rounded-xl border border-gray-100 transition">
                                <input type="checkbox"
                                       name="executor_ids[]"
                                       :value="op.id"
                                       :disabled="op.busy"
                                       x-model="selected"
                                       class="w-4 h-4 rounded accent-indigo-600">
                                <span class="text-sm font-medium text-gray-700" x-text="op.name"></span>
                                <span x-show="op.busy"
                                      class="ml-auto text-[10px] bg-orange-100 text-orange-600 px-2 py-0.5 rounded-full font-bold">
                                    Sedang mengerjakan
                                </span>
                            </label>
                        </template>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                            :disabled="selected.length === 0"
                            :class="selected.length > 0 ? 'bg-indigo-600 hover:bg-indigo-700 cursor-pointer' : 'bg-indigo-300 cursor-not-allowed'"
                            class="flex-1 py-2 text-white text-sm font-bold rounded-xl transition">
                        ▶ Mulai Sekarang
                    </button>
                    <button type="button" @click="mulai = null; selected = []"
                            class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== MODAL: CATATAN ===== --}}
    <div x-show="catatan !== null"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
         @click.self="catatan = null"
         style="display:none;">

        <div x-show="catatan !== null"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden">

            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-gray-800 text-sm">Catatan Produksi</h3>
                    <p class="text-xs text-gray-400 mt-0.5" x-text="catatan ? catatan.order_number : ''"></p>
                </div>
                <button type="button" @click="catatan = null"
                        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center text-sm">✕</button>
            </div>

            <form method="POST" class="p-5" :action="catatan ? catatan.action_url : '#'">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1.5">
                        Info penting eksekusi
                        <span class="text-gray-400 font-normal">(mis. "diambil Sabtu sore")</span>
                    </label>
                    <textarea name="notes" rows="3" placeholder="Tulis catatan penting untuk tim produksi..."
                              :value="catatan ? catatan.notes : ''"
                              class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-300 resize-none"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit"
                            class="flex-1 py-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-xl transition">
                        Simpan Catatan
                    </button>
                    <button type="button" @click="catatan = null"
                            class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== MODAL: SELESAI ===== --}}
    <div x-show="selesai !== null"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
         @click.self="selesai = null"
         style="display:none;">

        <div x-show="selesai !== null"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-2xl shadow-xl w-full overflow-hidden"
             :class="selesai && selesai.is_last ? 'max-w-lg' : 'max-w-sm'">

            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-black text-gray-800 text-sm">
                    <span x-show="!(selesai && selesai.is_last)">Konfirmasi Selesai</span>
                    <span x-show="selesai && selesai.is_last">Konfirmasi Hasil Produksi</span>
                </h3>
                <button type="button" @click="selesai = null"
                        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center text-sm">✕</button>
            </div>

            {{-- Form action is set dynamically via Alpine x-bind --}}
            <form id="form-selesai" method="POST" class="p-5" :action="selesai ? selesai.action_url : '#'">
                @csrf

                {{-- Default copy untuk langkah biasa --}}
                <p class="text-sm text-gray-600 mb-4" x-show="!(selesai && selesai.is_last)">
                    Tandai langkah ini sebagai selesai. Langkah selanjutnya akan masuk ke antrean divisi berikutnya.
                </p>

                {{-- Langkah terakhir: input qty aktual + persentase + keterangan per output (operator) --}}
                <template x-if="selesai && selesai.is_last">
                    <div class="mb-4 space-y-3">
                        <p class="text-xs text-gray-600 leading-relaxed bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                            Ini langkah terakhir untuk
                            <b class="text-amber-800" x-text="selesai.order_number"></b>.
                            Isi <b>qty hasil produksi yang bisa digunakan</b> untuk tiap output. Persentase biaya dihitung otomatis —
                            bila ada sampingan rusak, klik <b>Hitung Ulang Persentase</b> agar bebannya pindah ke produk utama.
                        </p>

                        <template x-for="(out, idx) in selesai.outputs" :key="out.id">
                            <div class="border border-gray-100 rounded-xl p-3 bg-gray-50/60">
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <span class="font-bold text-gray-800 text-sm" x-text="out.product_name"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded font-black"
                                          :class="out.output_type === 'main' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'"
                                          x-text="out.output_type === 'main' ? 'UTAMA' : 'SAMPINGAN'"></span>
                                    <span class="text-xs text-gray-400" x-show="out.product_sku" x-text="out.product_sku"></span>
                                </div>

                                <input type="hidden" :name="'outputs[' + idx + '][output_id]'" :value="out.id">

                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 mb-1">Target</label>
                                        <div class="text-sm font-bold text-gray-600 bg-white border border-gray-100 rounded-lg px-3 py-2 text-right"
                                             x-text="Number(out.qty_planned).toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 2})"></div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 mb-1">Qty Terpakai *</label>
                                        <input type="number" step="0.01" min="0" required
                                               x-model.number="out.qty_produced" @input="recalcSelesai()"
                                               :name="'outputs[' + idx + '][qty_produced]'"
                                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-green-400 bg-white text-right">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 mb-1">% Biaya (otomatis)</label>
                                        <div class="w-full border border-gray-100 rounded-lg px-3 py-2 text-sm font-bold text-right bg-gray-100 text-gray-600"
                                             x-text="(out.calc_percentage ?? 0) + '%'"></div>
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">
                                        Keterangan <span class="font-normal text-gray-400">(audit — penyebab selisih, kondisi material)</span>
                                    </label>
                                    <input type="text"
                                           :name="'outputs[' + idx + '][variance_notes]'"
                                           :value="out.variance_notes"
                                           placeholder="cth: 1 pcs cacat saat cutting, lembar akrilik tipis..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-300 bg-white">
                                </div>
                            </div>
                        </template>

                        <button type="button" @click="recalcSelesai()"
                                class="w-full border border-green-300 text-green-700 hover:bg-green-50 py-2 rounded-xl text-xs font-bold transition">
                            ↻ Hitung Ulang Persentase
                        </button>
                    </div>
                </template>

                <div class="mb-4">
                    <label class="block text-xs font-bold text-gray-600 mb-1.5">Catatan (opsional)</label>
                    <textarea name="notes" rows="2" placeholder="Catatan pengerjaan..."
                              class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-300 resize-none"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit"
                            class="flex-1 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-bold rounded-xl transition">
                        ✓ Konfirmasi Selesai
                    </button>
                    <button type="button" @click="selesai = null"
                            class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== MODAL: DETIL ===== --}}
    <div x-show="detail !== null"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
         @click.self="detail = null"
         style="display:none;">

        <div x-show="detail !== null"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">

            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-2">
                    <span class="font-black text-gray-800 text-sm" x-text="detail?.order_number"></span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded font-black" :class="detail?.type_class" x-text="detail?.type_label"></span>
                </div>
                <button @click="detail = null" class="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center text-sm">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto p-5 space-y-5">

                {{-- Step info --}}
                <div class="bg-indigo-50 rounded-xl px-4 py-3">
                    <div class="text-[10px] font-black text-indigo-400 uppercase tracking-widest mb-1">Langkah Saat Ini</div>
                    <div class="text-sm font-bold text-indigo-800" x-text="'#' + detail?.step_number + ' — ' + detail?.step_name"></div>
                    <div class="text-xs text-indigo-600 mt-0.5" x-show="detail?.executor !== '—'">
                        👤 Operator: <span x-text="detail?.executor"></span>
                    </div>
                </div>

                {{-- Product --}}
                <div>
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Produk Output</div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-bold text-gray-800" x-text="detail?.product_name"></span>
                        <span class="text-xs text-gray-400" x-show="detail?.product_sku !== '—'" x-text="'SKU: ' + detail?.product_sku"></span>
                        <span class="text-xs bg-green-50 text-green-600 border border-green-100 px-2 py-0.5 rounded font-bold" x-text="'Qty: ' + detail?.qty_planned"></span>
                        <span class="text-xs text-gray-400" x-show="detail?.target_date !== '—'" x-text="'Target: ' + detail?.target_date"></span>
                    </div>
                </div>

                {{-- Description --}}
                <div x-show="detail?.description">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Deskripsi / Instruksi</div>
                    <p class="text-sm text-gray-600 whitespace-pre-line" x-text="detail?.description"></p>
                </div>

                {{-- Steps list --}}
                <div x-show="detail?.steps?.length">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Alur Langkah Produksi</div>
                    <div class="space-y-1.5">
                        <template x-for="s in detail?.steps" :key="s.number">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-black flex-shrink-0"
                                      :class="{
                                          'bg-green-100 text-green-700': s.status === 'completed',
                                          'bg-indigo-100 text-indigo-700': s.status === 'in_progress',
                                          'bg-orange-100 text-orange-700': s.status === 'paused',
                                          'bg-gray-100 text-gray-500': s.status === 'pending',
                                      }"
                                      x-text="s.number"></span>
                                <span class="font-semibold text-gray-700" x-text="s.name"></span>
                                <span class="text-gray-400" x-text="'· ' + s.dept"></span>
                                <span class="ml-auto px-1.5 py-0.5 rounded text-[10px] font-bold"
                                      :class="{
                                          'bg-green-100 text-green-700': s.status === 'completed',
                                          'bg-indigo-100 text-indigo-700': s.status === 'in_progress',
                                          'bg-orange-100 text-orange-700': s.status === 'paused',
                                          'bg-gray-100 text-gray-500': s.status === 'pending',
                                      }"
                                      x-text="s.label"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Gambar Kerja --}}
                <div x-show="detail?.image_paths?.length">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Gambar Kerja</div>
                    <div class="grid grid-cols-3 gap-2">
                        <template x-for="(img, idx) in detail?.image_paths" :key="idx">
                            <a :href="'/storage/' + img" target="_blank"
                               class="block rounded-xl overflow-hidden border border-gray-200 hover:border-gray-400 transition">
                                <img :src="'/storage/' + img"
                                     class="w-full object-cover" style="height:90px;"
                                     x-on:error="$el.closest('a').style.display='none'">
                            </a>
                        </template>
                    </div>
                </div>

                {{-- Notes --}}
                <div x-show="detail?.notes">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Catatan</div>
                    <p class="text-sm text-gray-600" x-text="detail?.notes"></p>
                </div>

                {{-- Riwayat Timer --}}
                <div x-show="detail?.time_logs?.length">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 flex items-center justify-between">
                        <span>Riwayat Timer</span>
                        <span x-show="detail?.elapsed" class="text-indigo-600 font-mono normal-case" x-text="'Total: ' + detail?.elapsed"></span>
                    </div>
                    <div class="rounded-xl border border-gray-100 overflow-hidden">
                        <template x-for="(log, i) in detail?.time_logs" :key="i">
                            <div class="flex items-center gap-3 px-3 py-2 text-xs border-b border-gray-50 last:border-0"
                                 :class="{
                                     'bg-indigo-50/50': log.event === 'started' || log.event === 'resumed' || log.event === 'auto_resumed',
                                     'bg-orange-50/50': log.event === 'paused' || log.event === 'auto_paused',
                                     'bg-green-50/50':  log.event === 'completed',
                                 }">
                                <span class="w-20 flex-shrink-0 font-black text-[10px] px-1.5 py-0.5 rounded text-center"
                                      :class="{
                                          'bg-indigo-100 text-indigo-700': log.event === 'started' || log.event === 'resumed',
                                          'bg-purple-100 text-purple-700': log.event === 'auto_resumed',
                                          'bg-orange-100 text-orange-700': log.event === 'paused',
                                          'bg-gray-200 text-gray-600':    log.event === 'auto_paused',
                                          'bg-green-100 text-green-700':  log.event === 'completed',
                                      }"
                                      x-text="{
                                          started:      'Mulai',
                                          paused:       'Pause',
                                          resumed:      'Resume',
                                          completed:    'Selesai',
                                          auto_paused:  'Auto Pause',
                                          auto_resumed: 'Auto Resume',
                                      }[log.event] || log.event">
                                </span>
                                <span class="font-mono text-gray-500 flex-shrink-0" x-text="log.at"></span>
                                <span class="text-gray-400 truncate" x-show="log.notes" x-text="log.notes"></span>
                            </div>
                        </template>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ===== MODAL: GABUNG TASK ===== --}}
    <div x-show="$store.merge.confirmOpen" x-cloak
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
         @click.self="$store.merge.confirmOpen = false">

        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-black text-gray-800 text-sm">Gabungkan Task Produksi</h3>
                <button type="button" @click="$store.merge.confirmOpen = false"
                        class="w-7 h-7 rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 flex items-center justify-center text-sm">✕</button>
            </div>

            <form method="POST" action="{{ route('production.process.orders.merge') }}" class="p-5">
                @csrf
                <template x-for="i in $store.merge.items" :key="i.id">
                    <input type="hidden" name="ids[]" :value="i.id">
                </template>

                <p class="text-xs text-gray-500 mb-3">
                    Task berikut akan digabung menjadi satu. Task lain diserap ke task induk
                    (prioritas/progres tertinggi); semua harus berada di langkah yang sama.
                </p>

                <div class="space-y-1.5 mb-3 max-h-52 overflow-y-auto">
                    <template x-for="i in $store.merge.items" :key="i.id">
                        <div class="flex items-center justify-between gap-2 text-xs border border-gray-100 rounded-lg px-3 py-2">
                            <span class="truncate"><strong x-text="i.number"></strong> · <span x-text="i.product"></span></span>
                            <span class="text-gray-500 flex-shrink-0">qty <span x-text="i.qty"></span></span>
                        </div>
                    </template>
                </div>

                <div class="bg-indigo-50 rounded-lg px-3 py-2 text-xs text-indigo-800 mb-3">
                    Total qty gabungan: <strong x-text="$store.merge.combinedQty"></strong>
                </div>

                <p x-show="$store.merge.hasInProgress"
                   class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                    ⚠️ Ada task yang sedang dikerjakan. Task antre akan ikut masuk ke proses
                    yang sedang berjalan pada langkah yang sama.
                </p>

                <div class="flex gap-2">
                    <button type="submit"
                            class="flex-1 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl transition">
                        🔗 Gabungkan
                    </button>
                    <button type="button" @click="$store.merge.confirmOpen = false"
                            class="px-4 py-2 border border-gray-200 text-gray-600 text-sm font-semibold rounded-xl hover:bg-gray-50 transition">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<style>
[x-cloak] { display: none !important; }
</style>
@endsection

@push('scripts')
<script>
    // Store penggabungan task — dipakai checkbox kartu, bulk bar, & modal konfirmasi.
    document.addEventListener('alpine:init', () => {
        Alpine.store('merge', {
            items: [],
            confirmOpen: false,
            has(id) { return this.items.some(i => i.id === id); },
            toggle(o) {
                const idx = this.items.findIndex(i => i.id === o.id);
                if (idx >= 0) this.items.splice(idx, 1); else this.items.push(o);
            },
            clear() { this.items = []; this.confirmOpen = false; },
            get count() { return this.items.length; },
            get sigOk() {
                if (this.items.length < 2) return false;
                const s = this.items[0].sig, st = this.items[0].step;
                return this.items.every(i => i.sig === s && i.step === st);
            },
            get mismatch() { return this.items.length >= 2 && !this.sigOk; },
            get combinedQty() {
                return this.items.reduce((a, i) => a + (parseFloat(i.qty) || 0), 0);
            },
            get hasInProgress() {
                return this.items.some(i => i.status === 'in_progress' || i.status === 'paused');
            },
        });
    });
</script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
(function () {
    // Urutan awal saat halaman dimuat
    const initialOrder = @json($pendingSteps->pluck('id')->values());
    const pollUrl = '{{ route('production.process.queue-scores') }}';
    const deptId  = '{{ request('department_id', '') }}';
    const INTERVAL = 30000; // 30 detik

    function poll() {
        const url = new URL(pollUrl, window.location.origin);
        if (deptId) url.searchParams.set('department_id', deptId);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(function (data) {
                const newOrder = data.map(function (s) { return s.id; });
                if (JSON.stringify(initialOrder) !== JSON.stringify(newOrder)) {
                    document.getElementById('queue-changed-banner').classList.remove('hidden');
                }
            })
            .catch(function () {}); // abaikan error jaringan
    }

    // Mulai polling setelah interval pertama
    setInterval(poll, INTERVAL);

    // ──────────────────────────────────────────────────────────────────
    // TIMER VISUAL (kosmetik) — tick per detik untuk task in_progress.
    // Sumber kebenaran tetap di server (dihitung dari time logs + now()),
    // jadi nilai display di-resync tiap 30 menit untuk koreksi drift.
    // ──────────────────────────────────────────────────────────────────
    function fmtHMS(sec){
        sec = Math.max(0, Math.floor(sec));
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = sec % 60;
        return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }

    setInterval(function(){
        document.querySelectorAll('[data-timer-state="in_progress"]').forEach(function(el){
            const cur  = parseInt(el.dataset.timerSeconds, 10) || 0;
            const next = cur + 1;
            el.dataset.timerSeconds = next;
            el.textContent = fmtHMS(next);
        });
    }, 1000);

    const TIMER_RESYNC_URL = '{{ route('production.process.step-timers') }}';
    const TIMER_RESYNC_INTERVAL = 30 * 60 * 1000; // 30 menit

    function resyncTimers(){
        const url = new URL(TIMER_RESYNC_URL, window.location.origin);
        if (deptId) url.searchParams.set('department_id', deptId);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(function(data){
                data.forEach(function(t){
                    const el = document.querySelector('[data-timer-step="' + t.step_id + '"]');
                    if (!el) return;
                    el.dataset.timerSeconds = t.elapsed_seconds;
                    el.dataset.timerState   = t.state;
                    const prefix = t.state === 'paused' ? '⏸ ' : '';
                    el.textContent = prefix + fmtHMS(t.elapsed_seconds);
                });
            })
            .catch(function(){}); // abaikan error jaringan
    }
    setInterval(resyncTimers, TIMER_RESYNC_INTERVAL);
})();
</script>
@endpush
