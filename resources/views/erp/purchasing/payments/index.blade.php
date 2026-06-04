@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Supplier Payment</h1>
    <a href="{{ route('purchasing.payments.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Payment</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari No Payment atau supplier...'])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft','posted','void'] as $s)
                <option value="{{ $s }}" @selected(request('status')==$s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Payment</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Supplier</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Method</th>
                <th class="px-3 py-2 text-right">Nominal</th>
                <th class="px-3 py-2 text-right">Teralokasi</th>
                <th class="px-3 py-2 text-right">Sisa (DP)</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-56">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $p)
                @php
                    $isDraft = $p->status === 'draft';
                    $isPosted = $p->status === 'posted';
                    $rowHref = $isDraft
                        ? route('purchasing.payments.edit', $p->id)
                        : route('purchasing.payments.show', $p->id);

                    // Tipe: Pelunasan kalau ada manual allocation (is_auto_dp=false), selain itu DP.
                    $manualAllocs = $p->allocations->where('is_auto_dp', false);
                    $isPelunasan = $manualAllocs->count() > 0;

                    // Referensi: Pelunasan → INV pertama (+N kalau multi). DP → parse PO dari notes.
                    $refLabel = '';
                    if ($isPelunasan) {
                        $invNos = $manualAllocs->map(fn($a) => $a->invoice->invoice_number ?? '?')->values();
                        $refLabel = $invNos->first();
                        if ($invNos->count() > 1) $refLabel .= ' +' . ($invNos->count() - 1);
                    } else {
                        if (preg_match('/PO\/[^,\s]+/', (string) $p->notes, $m)) $refLabel = $m[0];
                    }
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium">
                        {{ $p->payment_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $p->payment_number])
                    </td>
                    <td class="px-3 py-2">{{ $p->payment_date->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $p->supplier->name }}</td>
                    <td class="px-3 py-2">
                        @if($isPelunasan)
                            <span class="px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-700 uppercase">Pelunasan</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs font-bold bg-purple-100 text-purple-700 uppercase">DP</span>
                        @endif
                        @if($refLabel)
                            <div class="text-[10px] text-gray-500 mt-0.5 font-mono">{{ $refLabel }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ ucfirst($p->payment_method) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($p->amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($p->allocated_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">
                        @if($p->remaining_amount > 0)
                            <span class="text-blue-600 font-semibold">{{ number_format($p->remaining_amount, 0, ',', '.') }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $cls = match($p->status) {
                                'posted' => 'bg-green-100 text-green-700',
                                'void' => 'bg-red-100 text-red-700',
                                default => 'bg-yellow-100 text-yellow-700',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $cls }}">{{ $p->status }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            <a href="{{ route('purchasing.payments.print', $p->id) }}"
                               class="bg-gray-700 text-white px-2 py-1 rounded text-xs" title="Print Payment">Print</a>

                            @if($p->canBeVoided())
                                <form method="POST" action="{{ route('purchasing.payments.void', $p->id) }}"
                                      onsubmit="return confirm('VOID payment {{ $p->payment_number }}?')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif

                            @if($isDraft)
                                <form method="POST" action="{{ route('purchasing.payments.destroy', $p->id) }}"
                                      onsubmit="return confirm('Hapus payment draft {{ $p->payment_number }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Belum ada payment.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $payments->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
