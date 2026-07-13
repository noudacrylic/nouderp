{{--
  Compact task card.
  Variables: $step (ProductionOrderStep), $panel ('pending'|'in_progress'|'paused')
--}}
@php
    $order   = $step->productionOrder;
    $mainOut = $order->outputs->where('output_type', 'main')->first();
    $product = $mainOut?->product;

    // Nama tampilan diambil dari baris Sales Order (deskripsi yang diketik user untuk
    // produk custom), bukan dari nama produk master. Fallback ke nama produk bila tak ada SO.
    $soItem      = $product ? $order->salesOrder?->items->firstWhere('product_id', $product->id) : null;
    $displayName = ($soItem && filled($soItem->description))
                     ? $soItem->description
                     : ($product?->name ?? '—');
    $isRepair = $order->isRepairLike();
    $tc      = match($order->type) {
        'ready_stock'                    => 'bg-blue-100 text-blue-700',
        'custom'                         => 'bg-purple-100 text-purple-700',
        'repair', 'perbaikan', 'garansi' => 'bg-orange-500 text-white',
        default                          => 'bg-gray-100 text-gray-600',
    };
    $numColor = match($panel) {
        'pending'     => 'bg-amber-100 text-amber-700',
        'in_progress' => 'bg-indigo-100 text-indigo-700',
        'paused'      => 'bg-orange-100 text-orange-700',
        default       => 'bg-gray-100 text-gray-500',
    };

    // Jumlah siklus produksi (berapa kali produksi) — info penting buat operator.
    $cycles = rtrim(rtrim(number_format((float) $order->planned_cycles, 4, '.', ''), '0'), '.');

    $elapsedSeconds = ($panel !== 'pending') ? $step->elapsed_working_seconds : 0;

    $detailPayload = [
        'order_number'   => $order->order_number,
        'type_label'     => strtoupper($order->type_label),
        'type_class'     => $tc,
        'step_name'      => $step->name,
        'step_number'    => $step->step_number,
        'description'    => $order->description,
        'product_name'   => $displayName,
        'product_sku'    => $product?->sku ?? '—',
        'is_repair'      => $isRepair,
        // Daftar SKU yang diperbaiki + qty (OP perbaikan/garansi bisa multi-SKU).
        'repair_items'   => $isRepair ? $order->outputs->map(fn($o) => [
            'sku'  => $o->product?->sku ?? '—',
            'name' => $o->product?->name ?? '—',
            'qty'  => rtrim(rtrim(number_format((float) $o->qty_planned, 2, '.', ''), '0'), '.'),
        ])->values()->toArray() : [],
        'qty_planned'    => $mainOut ? number_format((float)$mainOut->qty_planned, 0) : '—',
        'target_date'    => $order->target_completion_date?->format('d/m/Y') ?? '—',
        'executor'       => $step->executors->isNotEmpty()
                               ? $step->executors->pluck('name')->join(', ')
                               : ($step->executor?->name ?? '—'),
        'notes'          => $step->notes ?? '',
        'image_paths'    => collect((function() use ($order) {
                $raw = $order->getRawOriginal('image_paths');
                if (!$raw) return [];
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) return $decoded;
                if (is_string($decoded)) return json_decode($decoded, true) ?? [];
                return [];
            })())
            ->filter(fn($p) => $p && file_exists(public_path('storage/' . $p)))
            ->values()->toArray(),
        'steps'          => $order->steps->map(fn($s) => [
            'number' => $s->step_number,
            'name'   => $s->name,
            'dept'   => $s->department?->name ?? '—',
            'status' => $s->status,
            'label'  => $s->status_label,
        ])->values()->toArray(),
        'time_logs'      => $step->timeLogs->map(fn($l) => [
            'event' => $l->event_type,
            'at'    => $l->occurred_at->format('d/m/Y H:i:s'),
            'notes' => $l->notes ?? '',
        ])->values()->toArray(),
        'elapsed'        => $elapsedSeconds > 0
            ? sprintf('%02d:%02d:%02d', intdiv($elapsedSeconds,3600), intdiv($elapsedSeconds%3600,60), $elapsedSeconds%60)
            : null,
    ];

    // Cek apakah ini langkah terakhir agar modal "Selesai" sekalian minta qty aktual output
    // (operator yang isi qty hasil produksi, bukan admin saat finalisasi).
    $totalSteps = $order->steps->count();
    $isLastStep = (int) $step->step_number === (int) $totalSteps;

    $selesaiPayload = [
        'step_id'        => $step->id,
        'action_url'     => route('production.process.steps.complete', $step->id),
        'is_last'        => $isLastStep,
        'order_number'   => $order->order_number,
        'warehouse_id'   => (int) $order->warehouse_id,
        'planned_cycles' => (float) ($order->planned_cycles ?: 1),
        // Operator boleh override % sampingan hanya bila order TANPA BOM & bukan Perbaikan
        // (ukuran material beda → % beda). Selain itu % otomatis/terkunci.
        'pct_manual'     => $order->bom_id === null && $order->type !== 'repair',
        'outputs'      => $isLastStep ? $order->outputs->map(function ($o) {
            $pct = (float) $o->percentage;
            return [
                'id'              => $o->id,
                'product_name'    => $o->product?->name ?? '—',
                'product_sku'     => $o->product?->sku ?? '',
                'output_type'     => $o->output_type,
                'percentage'      => rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'),
                'unit_percentage' => $o->unit_percentage !== null ? (float) $o->unit_percentage : null,
                'qty_planned'     => (float) $o->qty_planned,
                'qty_produced'    => (float) ($o->qty_produced ?? 0),
                'variance_notes'  => $o->variance_notes ?? '',
                // Alokasi gudang tersimpan (bila ada) untuk pre-fill saat retry finalisasi.
                'allocations'     => is_array($o->warehouse_allocations) ? $o->warehouse_allocations : null,
            ];
        })->values()->toArray() : [],
    ];

    $catatanPayload = [
        'action_url'   => route('production.process.orders.notes', $order->id),
        'order_number' => $order->order_number,
        'notes'        => $order->notes ?? '',
    ];

    $busy = $busyExecutorIds ?? [];
    $mulaiPayload = [
        'action_url'   => route('production.process.steps.start', $step->id),
        'step_name'    => $step->name,
        'order_number' => $order->order_number,
        'executors'    => $step->department
            ? $step->department->executors->map(fn($e) => [
                'id'   => $e->id,
                'name' => $e->name,
                'busy' => in_array($e->id, $busy),
              ])->values()->toArray()
            : [],
    ];
@endphp

{{-- Data tersimpan di x-data agar tidak ada masalah JSON encoding di @click attribute --}}
<div class="bg-white border border-gray-100 rounded-xl p-3 shadow-sm"
     x-data="{
         detailData: {{ Js::from($detailPayload) }},
         selesaiData: {{ Js::from($selesaiPayload) }},
         mulaiData: {{ Js::from($mulaiPayload) }},
         catatanData: {{ Js::from($catatanPayload) }}
     }">

    {{-- Row 1: queue/step number + PRODUCT NAME · SKU + (sub) step name + PO + type --}}
    <div class="flex items-start gap-2 mb-1">
        @if(!empty($order->merge_eligible))
            {{-- Checkbox penggabungan task (hanya muncul bila layak digabung) --}}
            <input type="checkbox"
                   class="w-4 h-4 mt-1.5 rounded accent-indigo-600 flex-shrink-0 cursor-pointer"
                   :checked="$store.merge.has({{ $order->id }})"
                   @change="$store.merge.toggle({
                       id: {{ $order->id }},
                       sig: @js($order->merge_sig),
                       step: {{ $order->merge_step }},
                       number: @js($order->order_number),
                       product: @js($displayName),
                       qty: {{ (float) ($mainOut->qty_planned ?? 0) }},
                       status: @js($order->status)
                   })"
                   title="Pilih untuk digabung dengan task berkomponen sama">
        @endif
        @if($panel === 'pending' && isset($queueNumber))
            {{-- Nomor urutan antrean (prioritas) --}}
            <span class="w-7 h-7 rounded-full {{ $numColor }} flex items-center justify-center text-xs font-black flex-shrink-0 ring-2 ring-amber-300 mt-0.5">
                {{ $queueNumber }}
            </span>
        @else
            <span class="w-7 h-7 rounded-full {{ $numColor }} flex items-center justify-center text-xs font-black flex-shrink-0 mt-0.5">
                {{ $step->step_number }}
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <div class="font-black text-gray-900 text-base leading-snug truncate">
                {{ $displayName }}
                @if($product?->sku)<span class="text-gray-500 font-bold"> · {{ $product->sku }}</span>@endif
                @if($mainOut)<span class="text-gray-400 font-bold"> ({{ number_format((float)$mainOut->qty_planned, 0) }})</span>@endif
            </div>
            <div class="flex items-center gap-1.5 flex-wrap leading-tight mt-0.5">
                <a href="{{ route('production.orders.show', $order->id) }}"
                   class="text-[11px] font-bold text-blue-600 hover:underline">{{ $order->order_number }}</a>
                <span class="text-[10px] px-1.5 py-0.5 rounded font-black {{ $tc }} @if($isRepair) ring-1 ring-orange-300 shadow-sm @endif">@if($isRepair)🔧 @endif{{ strtoupper($order->type_label) }}</span>
                @if($order->bom)
                    <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded font-bold bg-teal-50 text-teal-700 border border-teal-100"
                          title="BOM: {{ $order->bom->bom_number }}{{ filled($order->bom->name) ? ' — '.$order->bom->name : '' }}">
                        📋 {{ filled($order->bom->name) ? $order->bom->name : $order->bom->bom_number }}
                    </span>
                @endif
                <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-md font-black bg-indigo-600 text-white shadow-sm"
                      title="Jumlah siklus produksi — produksi {{ $cycles }} kali">
                    🔁 {{ $cycles }}× Produksi
                </span>
                @if(!empty($order->mergedChildren) && $order->mergedChildren->count() > 0)
                    <span class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded font-black bg-purple-100 text-purple-700 border border-purple-200"
                          title="Hasil gabungan dari {{ $order->mergedChildren->count() }} task lain: {{ $order->mergedChildren->pluck('order_number')->implode(', ') }}">
                        🔗 Gabungan ×{{ $order->mergedChildren->count() + 1 }}
                    </span>
                @endif
            </div>

            {{-- Catatan eksekusi (mis. "diambil Sabtu sore") — selalu bisa diedit, walau order sudah diposting --}}
            <div class="mt-1.5">
                @if(filled($order->notes))
                    <button type="button"
                            @click="$dispatch('open-catatan', catatanData)"
                            class="group w-full flex items-start gap-1.5 text-left transition">
                        <span class="text-[11px] text-gray-500 leading-snug whitespace-pre-line flex-1 group-hover:text-gray-700">{{ $order->notes }}</span>
                        <span class="text-[10px] text-gray-300 group-hover:text-gray-500 flex-shrink-0">✏️</span>
                    </button>
                @else
                    <button type="button"
                            @click="$dispatch('open-catatan', catatanData)"
                            class="text-[10px] font-semibold text-gray-400 hover:text-amber-600 transition">
                        + Tambah catatan
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Row 3: meta (executor for in_progress/paused, score for pending) --}}
    <div class="flex items-center gap-2 gap-y-1 flex-wrap mb-2.5 text-[10px] text-gray-400">
        @if($panel === 'pending')
            @if($order->score_type === 'priority')
                @php
                    $priorityColors = [
                        'very_high' => 'bg-red-50 text-red-600 border-red-100',
                        'high'      => 'bg-orange-50 text-orange-600 border-orange-100',
                        'medium'    => 'bg-yellow-50 text-yellow-600 border-yellow-100',
                        'low'       => 'bg-gray-50 text-gray-500 border-gray-100',
                    ];
                    $priorityColor = $priorityColors[$order->priority_level] ?? 'bg-gray-50 text-gray-500 border-gray-100';
                @endphp
                <span class="border px-1.5 py-0.5 rounded font-bold {{ $priorityColor }}">
                    ★ {{ $order->priority_label }} ({{ (int)$order->effective_score }})
                </span>
            @elseif($order->type === 'custom')
                <span class="bg-violet-50 text-violet-600 border border-violet-100 px-1.5 py-0.5 rounded font-bold">
                    Custom (Score {{ number_format($order->effective_score, 2) }})
                </span>
            @elseif($order->bom?->score !== null)
                <span class="bg-yellow-50 text-yellow-600 border border-yellow-100 px-1.5 py-0.5 rounded font-bold">
                    Score {{ number_format($order->effective_score, 2) }}
                </span>
            @endif
            <span class="text-gray-400">Langkah {{ $step->step_number }}: <span class="font-semibold text-gray-600">{{ $step->name }}</span></span>
        @else
            @php
                $execNames = $step->executors->isNotEmpty()
                    ? $step->executors->pluck('name')->join(', ')
                    : $step->executor?->name;
            @endphp
            <span class="text-gray-400">Langkah {{ $step->step_number }}: <span class="font-semibold text-gray-600">{{ $step->name }}</span></span>
            @if($execNames)
                <span class="text-gray-500 font-medium">👤 {{ $execNames }}</span>
            @endif
            @if($elapsedSeconds > 0)
                @php $dur = sprintf('%02d:%02d:%02d', intdiv($elapsedSeconds,3600), intdiv($elapsedSeconds%3600,60), $elapsedSeconds%60); @endphp
                @if($panel === 'in_progress')
                    <span class="font-mono text-indigo-600 font-bold bg-indigo-50 px-1.5 py-0.5 rounded"
                          title="Durasi kerja — auto-tick (resync server tiap 30 menit)"
                          data-timer-step="{{ $step->id }}"
                          data-timer-state="in_progress"
                          data-timer-seconds="{{ $elapsedSeconds }}">{{ $dur }}</span>
                @elseif($panel === 'paused')
                    <span class="font-mono text-orange-500 font-bold bg-orange-50 px-1.5 py-0.5 rounded"
                          data-timer-step="{{ $step->id }}"
                          data-timer-state="paused"
                          data-timer-seconds="{{ $elapsedSeconds }}">⏸ {{ $dur }}</span>
                @endif
            @endif
        @endif
    </div>

    {{-- Action buttons --}}
    <div class="flex items-center gap-1.5 flex-wrap">
        @if($panel === 'pending')
            <button type="button"
                    @click="$dispatch('open-mulai', mulaiData)"
                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg transition">
                ▶ Mulai
            </button>

            @php
                $prevStep = (int) $step->step_number > 1
                    ? $step->productionOrder->steps->firstWhere('step_number', $step->step_number - 1)
                    : null;
            @endphp
            @if($prevStep && $prevStep->status === 'completed')
                <form action="{{ route('production.process.steps.revert', $step->id) }}" method="POST" class="inline"
                      onsubmit="return confirm('Kembalikan ke Langkah {{ $prevStep->step_number }} ({{ $prevStep->name }})?\n\nLangkah ini akan keluar dari antrean, dan Langkah {{ $prevStep->step_number }} dikerjakan ulang dari awal (sesi & timer baru).')">
                    @csrf
                    <button type="submit"
                            class="px-3 py-1.5 border border-amber-300 text-amber-700 hover:bg-amber-50 text-xs font-semibold rounded-lg transition">
                        ← Langkah Sebelumnya
                    </button>
                </form>
            @endif

        @elseif($panel === 'in_progress')
            <form action="{{ route('production.process.steps.pause', $step->id) }}" method="POST" class="inline">
                @csrf
                <button type="submit"
                        class="px-3 py-1.5 bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold rounded-lg transition">
                    Pause
                </button>
            </form>
            <button type="button"
                    @click="$dispatch('open-selesai', selesaiData)"
                    class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-bold rounded-lg transition">
                Selesai
            </button>

        @elseif($panel === 'paused')
            <form action="{{ route('production.process.steps.resume', $step->id) }}" method="POST" class="inline">
                @csrf
                <button type="submit"
                        class="px-3 py-1.5 bg-indigo-500 hover:bg-indigo-600 text-white text-xs font-bold rounded-lg transition">
                    Resume
                </button>
            </form>
        @endif

        <a href="{{ route('production.material-additions.create', array_filter(['department_id' => $step->department_id, 'step_id' => $step->id])) }}"
           class="px-3 py-1.5 border border-blue-200 text-blue-600 hover:bg-blue-50 text-xs font-semibold rounded-lg transition">
            + Bahan
        </a>

        <button type="button"
                @click="$dispatch('open-detail', detailData)"
                class="px-3 py-1.5 border border-gray-200 text-gray-500 hover:bg-gray-50 text-xs font-semibold rounded-lg transition">
            Detil
        </button>
    </div>
</div>
