@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4" x-data="{ addStep: false, showFinalize: false }">

    <div class="flex justify-between items-start mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 mb-1">
                <a href="{{ route('production.orders.index') }}" class="hover:text-blue-600">Order Produksi</a>
                <span>›</span><span>{{ $order->order_number }}</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                {{ $order->order_number }}
                @php $sc = match($order->status) { 'draft'=>'bg-amber-100 text-amber-700 border-amber-200','confirmed'=>'bg-blue-100 text-blue-700 border-blue-200','in_progress'=>'bg-indigo-100 text-indigo-700 border-indigo-200','completed'=>'bg-green-100 text-green-700 border-green-200','pending'=>'bg-orange-100 text-orange-700 border-orange-200','finalized'=>'bg-emerald-100 text-emerald-700 border-emerald-200','cancelled'=>'bg-gray-100 text-gray-400 border-gray-200',default=>'bg-gray-100 text-gray-400 border-gray-200' }; @endphp
                <span class="text-sm px-3 py-1 rounded-full font-black uppercase tracking-widest border {{ $sc }}">{{ $order->status_label }}</span>
                @php $tc = match($order->type) { 'ready_stock'=>'bg-blue-100 text-blue-700','custom'=>'bg-purple-100 text-purple-700','repair'=>'bg-orange-100 text-orange-700' }; @endphp
                <span class="text-xs px-2 py-1 rounded font-black {{ $tc }}">{{ $order->type_label }}</span>
            </h1>
            <div class="flex gap-4 mt-1.5 text-sm text-gray-500">
                <span>Tanggal: <strong class="text-gray-700">{{ $order->production_date->format('d/m/Y') }}</strong></span>
                <span>Gudang: <strong class="text-gray-700">{{ $order->warehouse?->name ?? '-' }}</strong></span>
                @if($order->salesOrder)
                    <span>SO: <a href="{{ route('sales.orders.show', $order->sales_order_id) }}" class="text-blue-600 font-bold hover:underline">{{ $order->salesOrder->order_number }}</a></span>
                @endif
                @if(!empty($repairSource) && $repairSource['number'])
                    <span>{{ $repairSource['label'] }}:
                        @if($repairSource['url'])
                            <a href="{{ $repairSource['url'] }}" class="text-blue-600 font-bold hover:underline">{{ $repairSource['number'] }}</a>
                        @else
                            <strong class="text-gray-700">{{ $repairSource['number'] }}</strong>
                        @endif
                    </span>
                @endif
            </div>
        </div>
        <a href="{{ route('production.orders.index') }}" class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Kembali</a>
    </div>


    <div class="grid grid-cols-12 gap-5">

        <div class="col-span-8 space-y-5">

            {{-- Timeline Langkah --}}
            @php
                $totalSteps     = $order->steps->count();
                $completedSteps = $order->steps->where('status', 'completed')->count();
                $progressPct    = $totalSteps > 0 ? round($completedSteps / $totalSteps * 100) : 0;

                // Bangun node stepper: semua langkah + 1 node final "Selesai Produksi"
                $stepperNodes = [];
                foreach ($order->steps as $s) {
                    $stepperNodes[] = ['type' => 'step', 'step' => $s, 'status' => $s->status];
                }
                $allDone = $totalSteps > 0 && $completedSteps === $totalSteps;
                $finalStatus = match(true) {
                    $order->status === 'finalized'                 => 'completed',
                    $order->status === 'completed' || $allDone     => 'in_progress',
                    default                                        => 'pending',
                };
                $stepperNodes[] = ['type' => 'final', 'status' => $finalStatus];
            @endphp
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-700">Langkah Produksi</h3>
                    @if($totalSteps > 0)
                        <span class="text-xs text-gray-500 font-semibold">
                            <span class="text-blue-600 font-black">{{ $completedSteps }}</span> / {{ $totalSteps }} selesai · {{ $progressPct }}%
                        </span>
                    @endif
                </div>

                @if($totalSteps > 0)
                    <div class="mb-5 overflow-x-auto pb-1">
                        <div class="flex items-start" style="min-width: {{ count($stepperNodes) * 130 }}px;">
                            @foreach($stepperNodes as $i => $node)
                                @php
                                    $status = $node['status'];

                                    if ($node['type'] === 'step') {
                                        $s        = $node['step'];
                                        $title    = 'Langkah ' . $s->step_number;
                                        $subtitle = $s->department?->name ?: $s->name;
                                        $meta     = ($s->department && $s->department->name !== $s->name) ? $s->name : null;
                                        $execs    = $s->executors->isNotEmpty()
                                                        ? $s->executors->pluck('name')->join(', ')
                                                        : $s->executor?->name;
                                        $sec      = $s->elapsed_working_seconds;
                                        $dur      = $sec > 0 ? sprintf('%02d:%02d:%02d', intdiv($sec,3600), intdiv($sec%3600,60), $sec%60) : null;
                                        $circleNumber = $s->step_number;
                                    } else {
                                        $title        = 'Finalisasi';
                                        $subtitle     = 'Selesai Produksi';
                                        $meta         = null;
                                        $execs        = null;
                                        $dur          = null;
                                        $circleNumber = '🏁';
                                    }

                                    $circleClass = match($status) {
                                        'completed'   => 'bg-blue-600 text-white border-blue-600',
                                        'in_progress' => 'bg-blue-600 text-white border-blue-600 ring-4 ring-blue-100',
                                        'paused'      => 'bg-orange-500 text-white border-orange-500',
                                        default       => 'bg-white text-gray-400 border-gray-200',
                                    };
                                    $circleContent = match($status) {
                                        'completed'   => '✓',
                                        'paused'      => '⏸',
                                        default       => $circleNumber,
                                    };

                                    $prevDone   = $i > 0 && $stepperNodes[$i-1]['status'] === 'completed';
                                    $selfDone   = $status === 'completed';
                                    $isFirst    = $i === 0;
                                    $isLast     = $i === count($stepperNodes) - 1;
                                    $leftColor  = $prevDone ? 'bg-blue-600' : 'bg-gray-200';
                                    $rightColor = $selfDone ? 'bg-blue-600' : 'bg-gray-200';

                                    $titleColor = match($status) {
                                        'completed', 'in_progress' => 'text-blue-700',
                                        'paused'                   => 'text-orange-700',
                                        default                    => 'text-gray-400',
                                    };
                                    $statusLabel = match($status) {
                                        'completed'   => ($node['type'] === 'final' ? 'Selesai' : 'Selesai'),
                                        'in_progress' => ($node['type'] === 'final' ? 'Menunggu' : 'Sedang Dikerjakan'),
                                        'paused'      => 'Pending',
                                        default       => 'Antre',
                                    };
                                    $statusBadge = match($status) {
                                        'completed'   => 'bg-blue-100 text-blue-700',
                                        'in_progress' => 'bg-indigo-100 text-indigo-700',
                                        'paused'      => 'bg-orange-100 text-orange-700',
                                        default       => 'bg-gray-100 text-gray-500',
                                    };
                                @endphp
                                <div class="flex flex-col items-center flex-1 min-w-[120px]">
                                    <div class="flex items-center w-full">
                                        <div class="h-0.5 flex-1 {{ $isFirst ? 'bg-transparent' : $leftColor }}"></div>
                                        <div class="relative flex-shrink-0">
                                            <span class="w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-black {{ $circleClass }}">
                                                {{ $circleContent }}
                                            </span>
                                            @if($status === 'in_progress')
                                                <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-indigo-500 rounded-full ring-2 ring-white animate-pulse"></span>
                                            @endif
                                        </div>
                                        <div class="h-0.5 flex-1 {{ $isLast ? 'bg-transparent' : $rightColor }}"></div>
                                    </div>
                                    <div class="mt-2 text-center px-1 w-full">
                                        <div class="text-[10px] font-black uppercase tracking-wider {{ $titleColor }}">{{ $title }}</div>
                                        <div class="text-xs font-bold text-gray-700 truncate" title="{{ $subtitle }}">{{ $subtitle }}</div>
                                        @if($meta)
                                            <div class="text-[10px] text-gray-400 truncate" title="{{ $meta }}">{{ $meta }}</div>
                                        @endif
                                        <div class="mt-1">
                                            <span class="text-[10px] inline-block px-1.5 py-0.5 rounded font-bold {{ $statusBadge }}">{{ $statusLabel }}</span>
                                        </div>
                                        @if($execs && ($status === 'in_progress' || $status === 'paused'))
                                            <div class="text-[10px] text-gray-600 mt-1 font-semibold truncate" title="{{ $execs }}">👤 {{ $execs }}</div>
                                        @endif
                                        @if($dur && $status === 'in_progress')
                                            <div class="text-[10px] text-indigo-600 font-mono font-bold mt-0.5">{{ $dur }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($order->steps->isEmpty())
                    <p class="text-xs text-gray-400 text-center py-4">Belum ada langkah. Tambahkan di bawah.</p>
                @else
                    <ol class="space-y-3">
                        @foreach($order->steps as $step)
                            @php
                                $stepBg = match($step->status) {
                                    'completed'   => 'bg-green-50 border-green-100',
                                    'in_progress' => 'bg-indigo-50 border-indigo-200 ring-2 ring-indigo-100',
                                    'paused'      => 'bg-orange-50 border-orange-100',
                                    default       => 'bg-gray-50 border-gray-100',
                                };
                                $numBg = match($step->status) {
                                    'completed'   => 'bg-green-500 text-white',
                                    'in_progress' => 'bg-indigo-600 text-white',
                                    'paused'      => 'bg-orange-500 text-white',
                                    default       => 'bg-gray-200 text-gray-500',
                                };
                                $numContent = match($step->status) {
                                    'completed'   => '✓',
                                    'in_progress' => '▶',
                                    'paused'      => '⏸',
                                    default       => $step->step_number,
                                };
                                $statusColor = match($step->status) {
                                    'completed'   => 'text-green-600',
                                    'in_progress' => 'text-indigo-600',
                                    'paused'      => 'text-orange-600',
                                    default       => 'text-gray-400',
                                };
                                $stepExecs = $step->executors->isNotEmpty()
                                    ? $step->executors->pluck('name')->join(', ')
                                    : $step->executor?->name;
                                $stepSec = $step->elapsed_working_seconds;
                                $stepDur = $stepSec > 0
                                    ? sprintf('%02d:%02d:%02d', intdiv($stepSec,3600), intdiv($stepSec%3600,60), $stepSec%60)
                                    : null;
                                $durStyle = match($step->status) {
                                    'in_progress' => 'text-indigo-700 bg-indigo-100',
                                    'paused'      => 'text-orange-700 bg-orange-100',
                                    'completed'   => 'text-green-700 bg-green-100',
                                    default       => 'text-gray-600 bg-gray-100',
                                };
                            @endphp
                            <li class="flex gap-3 items-start p-3 rounded-xl border {{ $stepBg }}">
                                <span class="w-7 h-7 rounded-full text-xs font-black flex items-center justify-center flex-shrink-0 {{ $numBg }}">
                                    {{ $numContent }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-gray-800 text-sm">{{ $step->name }}</span>
                                        @if($step->status === 'in_progress')
                                            <span class="w-1.5 h-1.5 bg-indigo-500 rounded-full animate-pulse"></span>
                                        @endif
                                    </div>
                                    <div class="flex gap-2 text-xs text-gray-500 mt-1 flex-wrap items-center">
                                        @if($step->department)
                                            <span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded font-bold text-[10px]">{{ $step->department->name }}</span>
                                        @endif
                                        <span class="font-bold {{ $statusColor }}">{{ $step->status_label }}</span>
                                        @if($stepExecs)
                                            <span class="text-gray-600">👤 <span class="font-semibold">{{ $stepExecs }}</span></span>
                                        @endif
                                        @if($stepDur)
                                            <span class="font-mono font-bold px-1.5 py-0.5 rounded {{ $durStyle }}">{{ $stepDur }}</span>
                                        @endif
                                        @if($step->started_at && $step->status !== 'pending')
                                            <span>Mulai: {{ $step->started_at->format('d/m H:i') }}</span>
                                        @endif
                                        @if($step->completed_at)
                                            <span>Selesai: {{ $step->completed_at->format('d/m H:i') }}</span>
                                        @endif
                                    </div>
                                    @if($step->notes) <div class="text-xs text-gray-400 mt-1 italic">{{ $step->notes }}</div> @endif
                                </div>
                                {{-- Hapus langkah: hanya sebelum produksi dimulai (draft/confirmed) & langkah masih antre --}}
                                @if(in_array($order->status, ['draft', 'confirmed']) && $step->status === 'pending')
                                    <form method="POST" action="{{ route('production.orders.steps.delete', [$order->id, $step->id]) }}"
                                          onsubmit="return confirm('Hapus langkah {{ $step->name }}?')" class="flex-shrink-0">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-gray-300 hover:text-red-500 text-base leading-none" title="Hapus langkah">✕</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                {{-- Tambah/Edit Langkah (draft & confirmed only) --}}
                @if(in_array($order->status, ['draft', 'confirmed']))
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <button @click="addStep = !addStep" class="text-xs text-blue-600 font-bold hover:text-blue-700">
                            {{ $order->steps->isEmpty() ? '+ Tambah Langkah Produksi' : '✎ Edit Langkah' }}
                        </button>

                        <div x-show="addStep" x-data="stepForm({{ json_encode($order->steps->map(fn($s)=>['name'=>$s->name,'department_id'=>$s->department_id,'bom_step_id'=>$s->bom_step_id])->values()) }})" class="mt-3">
                            <form action="{{ route('production.orders.steps.store', $order->id) }}" method="POST">
                                @csrf
                                <div class="space-y-2" id="step-list">
                                    <template x-for="(s, idx) in steps" :key="idx">
                                        <div class="flex gap-2 items-center p-2 bg-gray-50 rounded-lg">
                                            <span class="w-5 h-5 rounded-full bg-gray-200 text-gray-600 text-[9px] font-black flex items-center justify-center" x-text="idx+1"></span>
                                            <select :name="`steps[${idx}][department_id]`" x-model="s.department_id"
                                                    class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                                <option value="">— Pilih Divisi —</option>
                                                @foreach($departments as $dept)
                                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" @click="steps.splice(idx,1)" class="text-red-400 hover:text-red-600 text-sm">✕</button>
                                        </div>
                                    </template>
                                </div>
                                <div class="flex gap-2 mt-2">
                                    <button type="button" @click="steps.push({department_id:'',bom_step_id:null})"
                                            class="text-xs text-blue-600 font-bold">+ Langkah</button>
                                    <button type="submit" class="ml-auto bg-blue-600 text-white px-4 py-1.5 rounded-lg text-xs font-bold hover:bg-blue-700 transition">Simpan Langkah</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif

                {{-- Tambah langkah saat produksi berjalan: hanya APPEND, langkah lama tidak disentuh --}}
                @if($order->status === 'in_progress')
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <form action="{{ route('production.orders.steps.add', $order->id) }}" method="POST" class="flex gap-2 items-center">
                            @csrf
                            <span class="text-xs text-gray-500 font-bold whitespace-nowrap">+ Tambah langkah:</span>
                            <select name="department_id" required
                                    class="flex-1 border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                                <option value="">— Pilih Divisi —</option>
                                @foreach($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-xs font-bold hover:bg-blue-700 transition">Tambah</button>
                        </form>
                        <p class="text-[11px] text-gray-400 mt-1.5">Langkah baru masuk ke urutan akhir sebagai "Antre". Langkah yang sudah berjalan tidak bisa diubah atau dihapus.</p>
                    </div>
                @endif
            </div>

            {{-- Gambar Kerja --}}
            @if(!in_array($order->status, ['finalized', 'cancelled']))
                @php
                    $rawImg = $order->getRawOriginal('image_paths');
                    $workImages = [];
                    if ($rawImg) {
                        $dec = json_decode($rawImg, true);
                        if (is_string($dec)) $dec = json_decode($dec, true);
                        $workImages = is_array($dec) ? array_values(array_filter($dec)) : [];
                    }
                @endphp
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-3">Gambar Kerja</h3>
                    <form action="{{ route('production.orders.images.update', $order->id) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @if(count($workImages))
                            <div class="grid grid-cols-4 gap-3 mb-3">
                                @foreach($workImages as $img)
                                    <div class="relative">
                                        <a href="{{ asset('storage/' . $img) }}" target="_blank">
                                            <img src="{{ asset('storage/' . $img) }}" class="w-full h-24 object-cover rounded-lg border border-gray-200">
                                        </a>
                                        <label class="absolute top-1 right-1 bg-white/90 rounded px-1.5 py-0.5 text-[10px] flex items-center gap-1 shadow cursor-pointer">
                                            <input type="checkbox" name="remove[]" value="{{ $img }}" class="accent-red-500"> Hapus
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-gray-400 mb-3">Belum ada gambar kerja.</p>
                        @endif
                        <input type="file" name="images[]" multiple accept="image/*"
                               class="block w-full text-xs text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-blue-50 file:text-blue-700 file:font-bold hover:file:bg-blue-100">
                        <div class="flex justify-end mt-3">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-xs font-bold hover:bg-blue-700 transition">Simpan Gambar</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Material --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                @php
                    $shortageRows = collect($order->materials)
                        ->filter(fn($m) => (float) $m->qty_consumed <= 0
                            && (float) ($materialStock[$m->product_id] ?? 0) < (float) $m->qty_required);
                @endphp

                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-700">Material</h3>
                    @if($shortageRows->isNotEmpty())
                        <span class="text-[11px] font-bold px-2 py-0.5 rounded bg-orange-100 text-orange-700 border border-orange-200">
                            ⚠ {{ $shortageRows->count() }} material kurang stok
                        </span>
                    @endif
                </div>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-100">
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase">Produk</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Qty Dibutuhkan</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Qty Dikonsumsi</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Stok Tersedia</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Kurang</th>
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase">Satuan</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($order->materials as $m)
                            @php
                                $consumed = (float) $m->qty_consumed;
                                $required = (float) $m->qty_required;
                                $available = (float) ($materialStock[$m->product_id] ?? 0);
                                $isConsumed = $consumed > 0;
                                $shortage = $isConsumed ? 0 : max(0, $required - $available);
                                $hasShortage = $shortage > 0;
                            @endphp
                            <tr class="{{ $hasShortage ? 'bg-orange-50/60' : '' }}">
                                <td class="py-2.5 text-gray-700">{{ $m->product?->name ?? '-' }}</td>
                                <td class="py-2.5 text-center font-bold">{{ number_format($required, 2) }}</td>
                                <td class="py-2.5 text-center {{ $isConsumed ? 'text-green-600 font-bold' : 'text-gray-300' }}">
                                    {{ $isConsumed ? number_format($consumed, 2) : '—' }}
                                </td>
                                <td class="py-2.5 text-center {{ $isConsumed ? 'text-gray-300' : ($hasShortage ? 'text-orange-700 font-bold' : 'text-gray-700 font-bold') }}">
                                    {{ $isConsumed ? '—' : number_format($available, 2) }}
                                </td>
                                <td class="py-2.5 text-center {{ $hasShortage ? 'text-red-600 font-black' : 'text-gray-300' }}">
                                    {{ $hasShortage ? '−'.number_format($shortage, 2) : '—' }}
                                </td>
                                <td class="py-2.5 text-gray-500">{{ $m->unit ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-4 text-center text-xs text-gray-400">Belum ada material</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if($shortageRows->isNotEmpty())
                    <div class="mt-3 px-3 py-2 rounded-lg bg-orange-50 border border-orange-100 text-[11px] text-orange-800">
                        Stok material yang ditandai oranye belum mencukupi. Lakukan pembelian atau penyesuaian stok terlebih dahulu sebelum finalisasi — order akan otomatis berstatus <b>Menunggu Stok</b> jika dipaksa.
                    </div>
                @endif
            </div>

            {{-- Output --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-700 mb-4">Output Produksi</h3>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-100">
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase">Produk</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Tipe</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Qty Rencana</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Qty Aktual</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @forelse($order->outputs as $o)
                            <tr>
                                <td class="py-2.5 text-gray-700">{{ $o->product?->name ?? '-' }}</td>
                                <td class="py-2.5 text-center">
                                    @if($o->output_type === 'main')
                                        <span class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded font-black">UTAMA</span>
                                    @else
                                        <span class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded font-black">SAMPINGAN</span>
                                    @endif
                                </td>
                                <td class="py-2.5 text-center font-bold">{{ number_format($o->qty_planned, 2) }}</td>
                                <td class="py-2.5 text-center {{ $o->qty_produced > 0 ? 'text-green-600 font-bold' : 'text-gray-300' }}">
                                    {{ $o->qty_produced > 0 ? number_format($o->qty_produced, 2) : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-center text-xs text-gray-400">Belum ada output</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Biaya Produksi --}}
            @if($order->costs->isNotEmpty())
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-700 mb-4">Biaya Produksi</h3>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-100">
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase">Keterangan</th>
                        <th class="text-right pb-2 text-[10px] font-black text-gray-400 uppercase">Jumlah</th>
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase pl-4">Kas / Bank</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($order->costs as $cost)
                            <tr>
                                <td class="py-2.5 text-gray-700">{{ $cost->description }}</td>
                                <td class="py-2.5 text-right font-bold text-gray-800">Rp {{ number_format($cost->amount, 0, ',', '.') }}</td>
                                <td class="py-2.5 pl-4 text-gray-500 text-xs">{{ $cost->cashAccount?->code }} · {{ $cost->cashAccount?->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-gray-200">
                            <td class="pt-2.5 text-xs font-black text-gray-400 uppercase">Total</td>
                            <td class="pt-2.5 text-right font-black text-gray-800">Rp {{ number_format($order->costs->sum('amount'), 0, ',', '.') }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @endif

            {{-- Durasi Kerja Per Langkah --}}
            @php
                $stepsWithTime = $order->steps->filter(fn($s) => $s->elapsed_working_seconds > 0);
            @endphp
            @if($stepsWithTime->isNotEmpty())
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-700 mb-4">Durasi Kerja</h3>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-100">
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase">Langkah</th>
                        <th class="text-left pb-2 text-[10px] font-black text-gray-400 uppercase">Divisi</th>
                        <th class="text-right pb-2 text-[10px] font-black text-gray-400 uppercase">Durasi</th>
                        <th class="text-center pb-2 text-[10px] font-black text-gray-400 uppercase">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($stepsWithTime as $s)
                        @php
                            $sec = $s->elapsed_working_seconds;
                            $dur = sprintf('%02d:%02d:%02d', intdiv($sec,3600), intdiv($sec%3600,60), $sec%60);
                        @endphp
                        <tr>
                            <td class="py-2 text-gray-700">#{{ $s->step_number }} {{ $s->name }}</td>
                            <td class="py-2 text-gray-500">{{ $s->department?->name ?? '—' }}</td>
                            <td class="py-2 text-right font-mono font-bold text-gray-800">{{ $dur }}</td>
                            <td class="py-2 text-center">
                                @if($s->status === 'completed')
                                    <span class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold">Selesai</span>
                                @elseif($s->status === 'in_progress')
                                    <span class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-bold">Berjalan</span>
                                @elseif($s->status === 'paused')
                                    <span class="text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full font-bold">Pending</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    @php
                        $totalSec = $stepsWithTime->sum('elapsed_working_seconds');
                        $totalDur = sprintf('%02d:%02d:%02d', intdiv($totalSec,3600), intdiv($totalSec%3600,60), $totalSec%60);
                    @endphp
                    <tfoot>
                        <tr class="border-t border-gray-200">
                            <td class="pt-2 text-xs font-black text-gray-400 uppercase" colspan="2">Total</td>
                            <td class="pt-2 text-right font-mono font-black text-gray-800">{{ $totalDur }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @endif

            {{-- Finalisasi (completed) --}}
            @if($order->status === 'completed' && $order->outputs->where('qty_produced', 0)->isNotEmpty())
                <div class="bg-white rounded-2xl border border-green-200 shadow-sm p-5">
                    <h3 class="font-bold text-gray-700 mb-4">Input Hasil Produksi Aktual</h3>
                    <form action="{{ route('production.orders.finalize', $order->id) }}" method="POST">
                        @csrf
                        <div class="space-y-3">
                            @foreach($order->outputs as $o)
                                <div class="flex items-center gap-3">
                                    <input type="hidden" name="outputs[{{ $loop->index }}][output_id]" value="{{ $o->id }}">
                                    <span class="flex-1 text-sm text-gray-700">{{ $o->product?->name ?? '-' }}</span>
                                    <span class="text-xs text-gray-400">Rencana: {{ $o->qty_planned }}</span>
                                    <input type="number" name="outputs[{{ $loop->index }}][qty_produced]"
                                           value="{{ $o->qty_planned }}" min="0" step="0.0001"
                                           class="w-28 border border-gray-200 rounded-lg px-3 py-2 text-sm text-center focus:outline-none focus:ring-2 focus:ring-green-400">
                                </div>
                            @endforeach
                        </div>
                        <button type="submit" onclick="return confirm('Finalisasi produksi? Stok output akan masuk dan jurnal dicatat.')"
                                class="mt-4 w-full bg-green-600 hover:bg-green-700 text-white py-3 rounded-xl text-sm font-bold transition">
                            ✓ Finalisasi & Update Stok
                        </button>
                    </form>
                </div>
            @endif

        </div>

        {{-- Sidebar --}}
        <div class="col-span-4 space-y-4">

            {{-- Aksi --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-600 text-sm mb-4">Aksi</h3>
                <div class="space-y-2">
                    @if($order->status === 'draft')
                        <form action="{{ route('production.orders.confirm', $order->id) }}" method="POST">
                            @csrf
                            <button type="submit"
                                    onclick="return confirm('Konfirmasi order produksi?\nMaterial akan dikeluarkan dari stok dan jurnal WIP dibuat.')"
                                    class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                                ✓ Konfirmasi & Mulai Produksi
                            </button>
                        </form>
                        <form action="{{ route('production.orders.cancel', $order->id) }}" method="POST"
                              onsubmit="return confirm('Batalkan order ini?')">
                            @csrf
                            <button type="submit" class="w-full border border-red-200 text-red-600 hover:bg-red-50 py-2.5 rounded-xl text-sm font-semibold transition">
                                Batalkan
                            </button>
                        </form>
                    @endif

                    @if(in_array($order->status, ['confirmed', 'in_progress']))
                        @php
                            // Divisi tempat order ini sedang diproses: langkah yang sedang dikerjakan,
                            // atau kalau belum ada — langkah antre paling depan (nomor terkecil).
                            $activeStep = $order->steps->firstWhere('status', 'in_progress')
                                ?? $order->steps->where('status', 'pending')->sortBy('step_number')->first();
                            $activeDeptId = $activeStep?->department_id;
                        @endphp
                        <a href="{{ route('production.process.index', array_filter(['department_id' => $activeDeptId])) }}"
                           class="block w-full text-center bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-sm font-bold transition">
                            Lihat di Proses Produksi
                            @if($activeStep?->department)
                                <span class="block text-[11px] font-semibold text-indigo-100 mt-0.5">→ Divisi {{ $activeStep->department->name }}</span>
                            @endif
                        </a>
                    @endif
                </div>
            </div>

            {{-- Prioritas --}}
            @php
                $priorityCurrent = $order->score_type === 'priority' ? $order->priority_level : 'auto';
                $priorityEditable = !in_array($order->status, ['finalized', 'cancelled']);
                $priorityBadge = $order->score_type === 'priority'
                    ? match($order->priority_level) {
                        'very_high' => 'bg-red-50 text-red-700 border-red-200',
                        'high'      => 'bg-orange-50 text-orange-700 border-orange-200',
                        'medium'    => 'bg-amber-50 text-amber-700 border-amber-200',
                        'low'       => 'bg-sky-50 text-sky-700 border-sky-200',
                        default     => 'bg-gray-50 text-gray-500 border-gray-200',
                      }
                    : 'bg-gray-50 text-gray-500 border-gray-200';
                $priorityLabelText = $order->score_type === 'priority'
                    ? '★ ' . $order->priority_label
                    : '⚙ Otomatis';
            @endphp
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-bold text-gray-600 text-sm">Prioritas Antrian</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded-full font-black border {{ $priorityBadge }}">
                        {{ $priorityLabelText }} · {{ number_format($order->effective_score, 0) }}
                    </span>
                </div>
                @if($priorityEditable)
                    <form action="{{ route('production.orders.priority.update', $order->id) }}" method="POST">
                        @csrf
                        <select name="value" onchange="this.form.submit()"
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm font-semibold text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-400">
                            <option value="auto"      {{ $priorityCurrent === 'auto'      ? 'selected' : '' }}>⚙ Otomatis (berdasar BOM)</option>
                            <option value="low"       {{ $priorityCurrent === 'low'       ? 'selected' : '' }}>Rendah</option>
                            <option value="medium"    {{ $priorityCurrent === 'medium'    ? 'selected' : '' }}>Sedang</option>
                            <option value="high"      {{ $priorityCurrent === 'high'      ? 'selected' : '' }}>Tinggi</option>
                            <option value="very_high" {{ $priorityCurrent === 'very_high' ? 'selected' : '' }}>Sangat Tinggi</option>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-2 leading-snug">Otomatis: antrian mengikuti skor BOM. Manual: urutan sesuai level. Dapat diubah selama produksi berjalan.</p>
                    </form>
                @else
                    <p class="text-xs text-gray-400">Prioritas terkunci karena order {{ $order->status_label }}.</p>
                @endif
            </div>

            {{-- Info --}}
            <div class="bg-gray-50 rounded-2xl border border-gray-100 p-4 text-xs text-gray-500 space-y-1">
                <div><span class="font-bold text-gray-600">Siklus Rencana:</span> {{ $order->planned_cycles }}</div>
                @if($order->bom)
                    <div><span class="font-bold text-gray-600">BOM:</span>
                        <a href="{{ route('production.boms.edit', $order->bom_id) }}" class="text-blue-600 font-bold hover:underline">{{ $order->bom->bom_number }}</a>
                    </div>
                @endif
                @if($order->target_completion_date)
                    <div><span class="font-bold text-gray-600">Target Selesai:</span> {{ $order->target_completion_date->format('d/m/Y') }}</div>
                @endif
                {{-- Catatan eksekusi — bisa diedit kapan saja, termasuk setelah order diposting --}}
                <div class="pt-1 border-t border-gray-200" x-data="{ editing: false }">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-gray-600">Catatan:</span>
                        <button type="button" x-show="!editing" @click="editing = true"
                                class="text-[11px] font-semibold text-blue-600 hover:underline">✏️ Edit</button>
                    </div>

                    <div x-show="!editing" class="mt-0.5">
                        @if(filled($order->notes))
                            <p class="text-gray-600 whitespace-pre-line">{{ $order->notes }}</p>
                        @else
                            <p class="text-gray-400 italic">Belum ada catatan.</p>
                        @endif
                    </div>

                    <form x-show="editing" method="POST"
                          action="{{ route('production.process.orders.notes', $order->id) }}"
                          class="mt-1.5 space-y-2" style="display:none;">
                        @csrf
                        <textarea name="notes" rows="3" placeholder="Info penting eksekusi (mis. &quot;diambil Sabtu sore&quot;)..."
                                  class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-300 resize-none">{{ $order->notes }}</textarea>
                        <div class="flex gap-2">
                            <button type="submit"
                                    class="px-3 py-1 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-lg transition">
                                Simpan
                            </button>
                            <button type="button" @click="editing = false"
                                    class="px-3 py-1 border border-gray-200 text-gray-600 text-xs font-semibold rounded-lg hover:bg-gray-50 transition">
                                Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>

    </div>
</div>
@endsection

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function stepForm(initialSteps) {
    return { steps: initialSteps.length ? initialSteps : [{ name: '', department_id: '', bom_step_id: null }] };
}
</script>
@endpush
