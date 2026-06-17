@extends('layouts.erp')

@section('content')
@php
    // Data per order utk modal Edit Finalisasi (qty/%/keterangan output yg bisa dikoreksi).
    $editData = $finalized->mapWithKeys(fn($o) => [$o->id => [
        'id'           => $o->id,
        'order_number' => $o->order_number,
        'action'       => route('production.orders.edit-finalize', $o->id),
        // % cost bisa di-override manual hanya utk order TANPA BOM & bukan Perbaikan.
        'pct_manual'   => is_null($o->bom_id) && $o->type !== 'repair',
        'outputs'      => $o->outputs->map(fn($out) => [
            'output_id'      => $out->id,
            'name'           => $out->product?->name ?? '—',
            'sku'            => $out->product?->sku ?? '',
            'output_type'    => $out->output_type,
            'qty_planned'    => (float) $out->qty_planned,
            'qty_produced'   => (float) ($out->qty_produced ?? 0),
            'percentage'     => (float) $out->percentage,
            'variance_notes' => $out->variance_notes ?? '',
        ])->values(),
    ]]);
@endphp
<div class="w-full px-6 py-4" x-data="finalizeEditor()">

    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-800">Finalisasi Produksi</h1>
            <p class="text-xs text-gray-500 mt-0.5">Riwayat order yang sudah difinalisasi dan masuk ke stok.</p>
        </div>
        <a href="{{ route('production.orders.index') }}"
           class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Order Produksi</a>
    </div>


    {{-- Menunggu Konfirmasi --}}
    @if($awaitingConfirm->isNotEmpty())
    <div class="mb-6">
        <h2 class="text-sm font-black text-amber-700 uppercase tracking-widest mb-3 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
            Menunggu Konfirmasi Output
            <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-black border border-amber-200">{{ $awaitingConfirm->count() }}</span>
        </h2>
        <div class="space-y-2">
            @foreach($awaitingConfirm as $order)
            @php
                $isPending = $order->status === 'pending';
                $cardBorder = $isPending ? 'border-orange-200' : 'border-amber-100';
                $btnClass   = $isPending ? 'bg-orange-500 hover:bg-orange-600' : 'bg-amber-500 hover:bg-amber-600';
                $btnLabel   = $isPending ? 'Coba Finalisasi Lagi →' : 'Konfirmasi Output →';
            @endphp
            <div class="bg-white border {{ $cardBorder }} rounded-2xl shadow-sm px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="{{ route('production.orders.show', $order->id) }}"
                       class="font-black text-blue-600 hover:underline text-sm">{{ $order->order_number }}</a>
                    @php $tc = match($order->type) { 'ready_stock'=>'bg-blue-100 text-blue-700','custom'=>'bg-purple-100 text-purple-700','repair'=>'bg-orange-100 text-orange-700', default=>'bg-gray-100 text-gray-600' }; @endphp
                    <span class="text-[10px] px-1.5 py-0.5 rounded font-black {{ $tc }}">{{ strtoupper($order->type_label) }}</span>
                    @if($isPending)
                        <span class="text-[10px] px-1.5 py-0.5 rounded font-black bg-orange-100 text-orange-700 border border-orange-200" title="Stok material belum mencukupi. Lengkapi pembelian/adjustment lalu coba finalisasi lagi.">
                            ⚠ MENUNGGU STOK
                        </span>
                    @endif
                    <div class="flex gap-1.5 flex-wrap">
                        @foreach($order->outputs as $out)
                            <span class="text-xs bg-gray-50 border border-gray-100 rounded-lg px-2 py-1">
                                {{ $out->product?->name }} · <strong>{{ number_format($out->qty_planned, 2) }}</strong>
                            </span>
                        @endforeach
                    </div>
                </div>
                <a href="{{ route('production.orders.finalize-confirm', $order->id) }}"
                   class="flex-shrink-0 px-4 py-2 {{ $btnClass }} text-white text-xs font-bold rounded-xl transition">
                    {{ $btnLabel }}
                </a>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Filter --}}
    <form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
        @include('erp.purchasing._partials.search-input', [
            'name' => 'search',
            'placeholder' => 'Cari No order, produk, atau eksekutor...',
        ])
        @include('erp.purchasing._partials.date-range', [
            'fromName' => 'from',
            'toName'   => 'to',
        ])
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tipe</label>
            <select name="type" class="filter-auto border rounded px-2 py-1.5">
                <option value="">Semua</option>
                <option value="ready_stock" @selected(request('type')=='ready_stock')>Ready Stock</option>
                <option value="custom"      @selected(request('type')=='custom')>Custom / Preorder</option>
                <option value="repair"      @selected(request('type')=='repair')>Repair</option>
            </select>
        </div>
    </form>

    {{-- Finalized Orders --}}
    <h2 class="text-sm font-black text-gray-500 uppercase tracking-widest mb-3 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-green-500 inline-block"></span>
        Riwayat Finalisasi
        <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full font-black border border-gray-200">{{ $finalized->count() }}</span>
    </h2>

    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">No Order</th>
                    <th class="px-3 py-2 text-left">Tipe</th>
                    <th class="px-3 py-2 text-left whitespace-nowrap">Tgl Selesai</th>
                    <th class="px-3 py-2 text-left">Output</th>
                    <th class="px-3 py-2 text-left">Eksekutor</th>
                    <th class="px-3 py-2 text-left">Divisi &amp; Waktu</th>
                    <th class="px-3 py-2 text-right whitespace-nowrap">Total Waktu</th>
                    <th class="px-3 py-2 text-right w-20">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($finalized as $order)
                @php
                    $totalSec = $order->steps->sum('elapsed_working_seconds');
                    $totalDur = sprintf('%02d:%02d:%02d', intdiv($totalSec,3600), intdiv($totalSec%3600,60), $totalSec%60);
                    $deptGroups = $order->steps->filter(fn($s) => $s->department_id)->groupBy('department_id');
                    $tc = match($order->type) { 'ready_stock'=>'bg-blue-100 text-blue-700','custom'=>'bg-purple-100 text-purple-700','repair'=>'bg-orange-100 text-orange-700', default=>'bg-gray-100 text-gray-600' };
                    $allOps = $order->steps->flatMap(fn($s) => $s->executors->pluck('display_name'))
                        ->merge($order->steps->filter(fn($s) => $s->executor)->map(fn($s) => $s->executor->display_name))
                        ->filter()->unique()->values();
                @endphp
                <tr class="border-b hover:bg-gray-50 cursor-pointer" data-href="{{ route('production.orders.show', $order->id) }}">
                    <td class="px-3 py-2 whitespace-nowrap">
                        <a href="{{ route('production.orders.show', $order->id) }}"
                           class="font-bold text-blue-600 hover:underline">{{ $order->order_number }}</a>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="text-[10px] px-1.5 py-0.5 rounded font-black {{ $tc }}">{{ strtoupper($order->type_label) }}</span>
                    </td>
                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap">
                        {{ $order->finalized_at?->format('d/m/Y H:i') ?? '—' }}
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex flex-col gap-0.5">
                            @foreach($order->outputs as $out)
                                <div class="text-xs">
                                    <span class="text-gray-700">{{ $out->product?->name ?? '—' }}</span>
                                    @if($out->output_type === 'by_product')
                                        <span class="text-[9px] text-gray-400 font-bold ml-0.5">SMP</span>
                                    @endif
                                    · <strong>{{ number_format((float)$out->qty_produced, 2) }}</strong>
                                    @if((float)$out->qty_produced != (float)$out->qty_planned)
                                        <span class="text-[10px] text-gray-400">(target {{ number_format((float)$out->qty_planned, 2) }})</span>
                                    @endif
                                    @if($out->variance_notes)<span class="text-amber-600 ml-1" title="{{ $out->variance_notes }}">⚠</span>@endif
                                </div>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-600">
                        @if($allOps->isNotEmpty())
                            {{ $allOps->join(', ') }}
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        @if($deptGroups->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach($deptGroups as $deptId => $steps)
                                @php
                                    $dSec = $steps->sum('elapsed_working_seconds');
                                    $dDur = sprintf('%02d:%02d:%02d', intdiv($dSec,3600), intdiv($dSec%3600,60), $dSec%60);
                                @endphp
                                <span class="text-[11px] bg-indigo-50 border border-indigo-100 rounded px-1.5 py-0.5 whitespace-nowrap">
                                    {{ $steps->first()->department?->name ?? '—' }}
                                    @if($dSec > 0)
                                        <span class="font-mono font-bold text-indigo-700 ml-0.5">{{ $dDur }}</span>
                                    @else
                                        <span class="text-gray-300 ml-0.5">—</span>
                                    @endif
                                </span>
                                @endforeach
                            </div>
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        @if($totalSec > 0)
                            <span class="text-xs font-mono text-indigo-600 font-bold">{{ $totalDur }}</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        {{-- Edit = koreksi salah input finalisasi. Balik + terapkan ulang qty output
                             secara atomik; FIFO & jurnal ikut teredit. Order tetap "selesai". --}}
                        <button type="button"
                                @click.stop="openEdit({{ $order->id }})"
                                class="px-2 py-1 border border-blue-200 text-blue-600 hover:bg-blue-50 text-xs font-bold rounded transition">
                            Edit
                        </button>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-3 py-8 text-center text-gray-400">
                    @if(request()->hasAny(['search','from','to','type']))
                        Tidak ada riwayat finalisasi yang cocok dengan filter.
                    @else
                        Belum ada order yang difinalisasi.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ───────── Modal Edit Finalisasi (koreksi salah input) ───────── --}}
    <div x-show="show" x-cloak
         class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 py-10 px-4"
         @keydown.escape.window="close()" style="display:none;">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl my-auto" @click.outside="close()">
            <template x-if="current">
                <form :action="current.action" method="POST">
                    @csrf
                    {{-- Header --}}
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                        <div>
                            <h2 class="font-bold text-gray-800">Edit Hasil Finalisasi</h2>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <span class="font-bold text-blue-600" x-text="current.order_number"></span>
                                · koreksi qty output — stok (FIFO) &amp; jurnal otomatis disesuaikan
                            </p>
                        </div>
                        <button type="button" @click="close()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                    </div>

                    {{-- Outputs --}}
                    <div class="p-5 space-y-3 max-h-[60vh] overflow-y-auto">
                        <template x-for="(o, idx) in current.outputs" :key="o.output_id">
                            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50/50">
                                <input type="hidden" :name="`outputs[${idx}][output_id]`" :value="o.output_id">

                                <div class="flex items-center gap-2 mb-3 flex-wrap">
                                    <span class="font-bold text-gray-800 text-sm" x-text="o.name"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded font-black"
                                          :class="o.output_type === 'main' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'"
                                          x-text="o.output_type === 'main' ? 'UTAMA' : 'SAMPINGAN'"></span>
                                    <span class="text-xs text-gray-400" x-text="o.sku"></span>
                                </div>

                                <div class="grid grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 mb-1">Target</label>
                                        <div class="text-sm font-bold text-gray-600 bg-gray-100 rounded-lg px-3 py-2 text-right"
                                             x-text="Number(o.qty_planned).toFixed(2)"></div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 mb-1">Qty Aktual *</label>
                                        <input type="number" :name="`outputs[${idx}][qty_produced]`"
                                               x-model="o.qty_produced" step="0.01" min="0" required
                                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white text-right">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-bold text-gray-500 mb-1">
                                            Persentase Cost (%)
                                            <span class="font-normal text-gray-400" x-show="!pctEditable(o)">(otomatis)</span>
                                        </label>
                                        <input type="number" :name="`outputs[${idx}][percentage]`"
                                               x-model="o.percentage" step="0.01" min="0" max="100"
                                               :required="pctEditable(o)" :readonly="!pctEditable(o)"
                                               :class="pctEditable(o) ? 'bg-white' : 'bg-gray-100 text-gray-600 cursor-not-allowed'"
                                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-blue-400 text-right">
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <label class="block text-[10px] font-bold text-gray-500 mb-1">
                                        Keterangan <span class="font-normal text-gray-400">(audit — penyebab selisih)</span>
                                    </label>
                                    <input type="text" :name="`outputs[${idx}][variance_notes]`"
                                           x-model="o.variance_notes"
                                           placeholder="cth: 1 pcs cacat saat cutting..."
                                           class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white text-gray-700">
                                </div>
                            </div>
                        </template>

                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-[11px] text-amber-800 leading-snug">
                            Menyimpan akan <b>membalik</b> finalisasi lama lalu <b>menerapkan ulang</b> dengan qty di atas —
                            stok output (FIFO) dan jurnal otomatis disesuaikan. Tidak bisa diedit bila stok output sudah terpakai
                            di Surat Jalan/transaksi lain (batalkan dokumen tersebut dulu).
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-100">
                        <button type="button" @click="close()"
                                class="px-4 py-2 border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-semibold rounded-xl transition">
                            Batal
                        </button>
                        <button type="submit"
                                onclick="return confirm('Simpan perubahan finalisasi? Stok (FIFO) & jurnal akan disesuaikan dengan qty baru.')"
                                class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition shadow-sm">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </div>

</div>

<script>
    window.__finalizeEdit = @json($editData);
    function finalizeEditor() {
        return {
            show: false,
            current: null,
            openEdit(id) {
                const src = (window.__finalizeEdit || {})[id];
                if (!src) return;
                // Clone dalam supaya editan di modal tidak mengubah data sumber sebelum disimpan.
                this.current = JSON.parse(JSON.stringify(src));
                this.show = true;
            },
            close() { this.show = false; this.current = null; },
            // % cost hanya bisa di-override manual utk sampingan pada order TANPA BOM & bukan Perbaikan.
            pctEditable(o) { return !!this.current?.pct_manual && o.output_type === 'by_product'; },
        };
    }
</script>

@include('erp.purchasing._partials.list-scripts')
@endsection
