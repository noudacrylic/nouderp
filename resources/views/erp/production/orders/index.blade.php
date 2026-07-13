@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Order Produksi</h1>
    <a href="{{ route('production.orders.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat Order Produksi</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari nomor order, BOM, atau SO...'])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @php
                $typeOptions = [
                    'ready_stock' => 'Ready Stock',
                    'custom'      => 'Preorder',
                    'perbaikan'   => 'Perbaikan',
                    'garansi'     => 'Garansi',
                    'repair'      => 'Perbaikan (lama)',
                ];
            @endphp
            @foreach($typeOptions as $val => $label)
                <option value="{{ $val }}" @selected(request('type')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @php
                $statusOptions = [
                    'draft'       => 'Draft',
                    'confirmed'   => 'Dikonfirmasi',
                    'in_progress' => 'Dalam Proses',
                    'completed'   => 'Menunggu Finalisasi',
                    'pending'     => 'Menunggu Stok',
                    'finalized'   => 'Selesai',
                ];
            @endphp
            @foreach($statusOptions as $val => $label)
                <option value="{{ $val }}" @selected(request('status')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Nomor PO</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">BOM / SO</th>
                <th class="px-3 py-2 text-center">Siklus</th>
                <th class="px-3 py-2 text-center">Prioritas</th>
                <th class="px-3 py-2 text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($orders as $order)
                @php
                    $typeCls = match($order->type) {
                        'ready_stock' => 'bg-blue-100 text-blue-700',
                        'custom'      => 'bg-purple-100 text-purple-700',
                        'repair'      => 'bg-orange-100 text-orange-700',
                        default       => 'bg-gray-100 text-gray-600',
                    };
                    $statusCls = match($order->status) {
                        'draft'       => 'bg-gray-100 text-gray-500',
                        'confirmed'   => 'bg-blue-100 text-blue-700',
                        'in_progress' => 'bg-indigo-100 text-indigo-700',
                        'completed'   => 'bg-amber-100 text-amber-700',
                        'pending'     => 'bg-orange-100 text-orange-700',
                        'finalized'   => 'bg-green-100 text-green-700',
                        'cancelled'   => 'bg-gray-200 text-gray-500',
                        'merged'      => 'bg-purple-100 text-purple-700',
                        default       => 'bg-gray-100 text-gray-500',
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('production.orders.show', $order->id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $order->order_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $order->order_number])
                    </td>
                    <td class="px-3 py-2">{{ $order->production_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $typeCls }}">{{ $order->type_label }}</span>
                    </td>
                    <td class="px-3 py-2 text-gray-600">
                        {{ $order->bom?->name ?? ($order->salesOrder ? 'SO: '.$order->salesOrder->order_number : '—') }}
                    </td>
                    <td class="px-3 py-2 text-center">{{ rtrim(rtrim(number_format((float)$order->planned_cycles, 4, '.', ''), '0'), '.') }}</td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        @if(in_array($order->status, ['finalized', 'cancelled']))
                            <span class="text-xs text-gray-500">
                                {{ $order->score_type === 'priority' ? '★ '.$order->priority_label : 'Otomatis' }}
                            </span>
                        @else
                            @php $cur = $order->score_type === 'priority' ? $order->priority_level : 'auto'; @endphp
                            <form action="{{ route('production.orders.priority.update', $order->id) }}" method="POST" class="inline-block">
                                @csrf
                                <select name="value" onchange="this.form.submit()"
                                        class="text-xs border rounded px-2 py-1 bg-white">
                                    <option value="auto"      {{ $cur === 'auto'      ? 'selected' : '' }}>Otomatis</option>
                                    <option value="low"       {{ $cur === 'low'       ? 'selected' : '' }}>Rendah</option>
                                    <option value="medium"    {{ $cur === 'medium'    ? 'selected' : '' }}>Sedang</option>
                                    <option value="high"      {{ $cur === 'high'      ? 'selected' : '' }}>Tinggi</option>
                                    <option value="very_high" {{ $cur === 'very_high' ? 'selected' : '' }}>Sangat Tinggi</option>
                                    <option value="urgent"    {{ $cur === 'urgent'    ? 'selected' : '' }}>Mendesak</option>
                                </select>
                            </form>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}"
                              @if($order->status === 'merged' && $order->mergedInto) title="Digabung ke {{ $order->mergedInto->order_number }}" @endif>{{ $order->status_label }}</span>
                        @if(in_array($order->status, ['confirmed', 'in_progress']))
                            @php
                                // Langkah yang sedang dikerjakan, atau langkah antre paling depan
                                $activeStep = $order->steps->firstWhere('status', 'in_progress')
                                    ?? $order->steps->where('status', 'pending')->sortBy('step_number')->first();
                            @endphp
                            @if($activeStep && $activeStep->department)
                                <div class="text-[10px] text-gray-500 mt-1 normal-case">
                                    {{ $activeStep->department->name }} ·
                                    @if($activeStep->status === 'in_progress')
                                        <span class="text-indigo-600 font-bold">Dikerjakan</span>
                                    @else
                                        <span class="text-gray-500">Antre</span>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada order produksi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $orders->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
