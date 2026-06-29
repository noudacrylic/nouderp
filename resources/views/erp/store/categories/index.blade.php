@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Kategori Store</h1>
    <a href="{{ route('store.categories.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Kategori</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ $q }}" placeholder="Nama / slug..." class="border rounded px-2 py-1.5 w-64">
    </div>
    @include('erp._partials.per-page-select')
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Slug</th>
                <th class="px-3 py-2 text-left">Induk</th>
                <th class="px-3 py-2 text-right">Jml Produk</th>
                <th class="px-3 py-2 text-right">Urutan</th>
                <th class="px-3 py-2 text-right w-36">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($categories as $cat)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 font-medium">{{ $cat->name }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $cat->slug }}</td>
                    <td class="px-3 py-2">{{ $cat->parent->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $cat->products_count }}</td>
                    <td class="px-3 py-2 text-right">{{ $cat->sort_order }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse">
                            <form method="POST" action="{{ route('store.categories.destroy', $cat->id) }}" onsubmit="return confirm('Hapus kategori? Produk yang memakainya tidak terhapus, hanya kehilangan kategori.')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                            <a href="{{ route('store.categories.edit', $cat->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Belum ada kategori.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $categories->links() }}</div>
@endsection
