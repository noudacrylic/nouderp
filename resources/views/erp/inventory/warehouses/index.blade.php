@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Warehouse</h1>
    <div class="flex gap-2">
        <a href="{{ route('inventory.warehouses.stock') }}" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Lihat Stok Gudang</a>
        <a href="{{ route('inventory.warehouses.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Warehouse</a>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari nama gudang atau lokasi...'])
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Warehouse</th>
                <th class="px-3 py-2 text-left">Location</th>
                <th class="px-3 py-2 text-center">Sellable</th>
                <th class="px-3 py-2 text-right">Total Stock</th>
                <th class="px-3 py-2 text-center w-56">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($warehouses as $warehouse)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('inventory.warehouses.show', $warehouse->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $warehouse->name }}</td>
                    <td class="px-3 py-2">{{ $warehouse->location }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        @if ($warehouse->is_sellable)
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-green-100 text-green-700">Yes</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-500">No</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ number_format($warehouse->stock_total ?? 0, 2) }}</td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap flex-row-reverse">
                            @if($warehouse->is_used)
                                <form method="POST" action="{{ route('inventory.warehouses.archive', $warehouse->id) }}"
                                      onsubmit="return confirm('Archive warehouse {{ $warehouse->name }}?')">
                                    @csrf
                                    <button class="bg-yellow-500 text-white px-2 py-1 rounded text-xs" title="Sudah dipakai — hanya bisa di-archive">Archive</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('inventory.warehouses.destroy', $warehouse->id) }}"
                                      onsubmit="return confirm('Hapus warehouse {{ $warehouse->name }}?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                            <a href="{{ route('inventory.warehouses.edit', $warehouse->id) }}"
                               class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada warehouse.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $warehouses->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
