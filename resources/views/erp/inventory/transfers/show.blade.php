@extends('layouts.erp')

@section('content')

    <div class="max-w-4xl mx-auto py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Rincian Transfer Stok</h1>
            <a href="{{ route('inventory.transfers.index') }}" class="text-blue-600 hover:underline text-sm flex items-center gap-1">
                 <x-heroicon-o-arrow-left class="w-4 h-4" /> Kembali ke Daftar
            </a>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden mb-6">
            <div class="p-6 border-b border-gray-50 bg-gray-50/50">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-sm">
                    <div>
                        <span class="text-gray-500 block mb-1">Nomor Transfer</span>
                        <span class="font-bold text-gray-800">{{ $transfer->number }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block mb-1">Tanggal</span>
                        <span class="font-bold text-gray-800">{{ $transfer->date }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block mb-1">Dari Gudang</span>
                        <span class="font-bold text-gray-800 text-red-600">{{ $transfer->fromWarehouse->name ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block mb-1">Ke Gudang</span>
                        <span class="font-bold text-gray-800 text-green-600">{{ $transfer->toWarehouse->name ?? '-' }}</span>
                    </div>
                </div>
                <div class="mt-4">
                    <span class="text-gray-500 text-xs block mb-1 uppercase tracking-wider font-semibold">Status</span>
                    @if ($transfer->status == 'draft')
                        <span class="px-3 py-1 text-xs font-bold uppercase bg-gray-100 text-gray-500 rounded-full">Draf</span>
                    @elseif($transfer->status == 'posted')
                        <span class="px-3 py-1 text-xs font-bold uppercase bg-green-100 text-green-700 rounded-full">Diposting</span>
                    @elseif($transfer->status == 'void')
                        <span class="px-3 py-1 text-xs font-bold uppercase bg-red-100 text-red-700 rounded-full">Void</span>
                    @endif
                </div>
            </div>

            <div class="p-0">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 border-b border-gray-100">
                        <tr>
                            <th class="p-4 text-left font-semibold">Produk</th>
                            <th class="p-4 text-right font-semibold">Qty Transfer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach ($transfer->items as $item)
                            <tr>
                                <td class="p-4">
                                    <div class="font-medium text-gray-800">{{ $item->product->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $item->product->sku }}</div>
                                </td>
                                <td class="p-4 text-right font-bold text-gray-800">
                                    {{ number_format($item->qty, 0) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if($transfer->status == 'draft')
        <div class="flex justify-end gap-3 text-sm font-semibold">
            <a href="{{ route('inventory.transfers.edit', $transfer->id) }}" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">
                Edit Draf
            </a>
            <form method="POST" action="{{ route('inventory.transfers.post', $transfer->id) }}" onsubmit="return confirm('Post transfer?')">
                @csrf
                <button class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">
                    Post Transfer
                </button>
            </form>
        </div>
        @endif
    </div>

@endsection
