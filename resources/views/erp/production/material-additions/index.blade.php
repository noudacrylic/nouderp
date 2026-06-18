@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Penambahan Bahan</h1>
    <a href="{{ route('production.material-additions.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Bahan</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari nomor tambah bahan atau order produksi...'])
    @include('erp.purchasing._partials.date-range')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Nomor</th>
                <th class="px-3 py-2 text-left">Order Produksi</th>
                <th class="px-3 py-2 text-left">Langkah</th>
                <th class="px-3 py-2 text-center">Jumlah Item</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Catatan</th>
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($additions as $add)
                <tr class="border-b hover:bg-blue-50 cursor-pointer {{ $add->isVoided() ? 'opacity-50' : '' }}" data-href="{{ route('production.orders.show', $add->production_order_id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $add->addition_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $add->addition_number])
                        @if($add->isVoided())
                            <span class="ml-1 px-2 py-0.5 rounded text-[10px] bg-gray-200 text-gray-600 uppercase font-bold">Dibatalkan</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $add->productionOrder->order_number ?? '—' }}</td>
                    <td class="px-3 py-2 text-gray-600">
                        @if($add->step)
                            Langkah {{ $add->step->step_number }}: {{ $add->step->name }}
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700">{{ $add->items->count() }} bahan</span>
                    </td>
                    <td class="px-3 py-2">{{ $add->created_at->format('d M Y H:i') }}</td>
                    <td class="px-3 py-2 text-gray-500 max-w-xs truncate">{{ $add->notes ?? '—' }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                        @if($add->canBeVoided())
                            <form method="POST" action="{{ route('production.material-additions.void', $add->id) }}"
                                  onsubmit="return confirm('Batalkan penambahan bahan {{ $add->addition_number }}? Stok akan dikembalikan & jurnal WIP dibalik.')">
                                @csrf
                                <button type="submit"
                                        class="border border-red-300 text-red-600 hover:bg-red-50 px-3 py-1 rounded text-xs font-semibold">Void</button>
                            </form>
                        @elseif($add->isVoided())
                            <span class="text-xs text-gray-400">—</span>
                        @else
                            <span class="text-[11px] text-gray-400" title="Void hanya bisa selagi order masih dikerjakan">terkunci</span>
                        @endif
                    </td>
                </tr>
                @foreach($add->items as $item)
                    <tr class="border-b bg-blue-50/30 text-xs">
                        <td class="pl-8 pr-3 py-1 text-gray-400">↳</td>
                        <td colspan="2" class="px-3 py-1 text-gray-700">
                            {{ $item->product->name ?? '—' }}
                            @if($item->product?->sku)
                                <span class="text-gray-400">· {{ $item->product->sku }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-1 text-center font-medium text-gray-700">
                            {{ rtrim(rtrim(number_format((float)$item->qty_requested, 4, ',', '.'), '0'), ',') }}
                            {{ $item->unit }}
                        </td>
                        <td colspan="3" class="px-3 py-1 text-gray-400">{{ $item->notes }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada penambahan bahan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $additions->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
