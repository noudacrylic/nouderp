@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Produk Store</h1>
    <a href="{{ route('store.products.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Produk</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ $q }}" placeholder="Nama / slug..." class="border rounded px-2 py-1.5 w-64">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="published" @selected($status === 'published')>Published</option>
            <option value="draft"     @selected($status === 'draft')>Draft</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Kategori</th>
                <th class="px-3 py-2 text-right">Varian</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-center">Unggulan</th>
                <th class="px-3 py-2 text-right w-36">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $p)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $p->name }}</div>
                        <div class="text-xs text-gray-400">{{ $p->slug }}</div>
                    </td>
                    <td class="px-3 py-2">{{ $p->category->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $p->variants_count }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($p->status === 'published')
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs">Published</span>
                        @else
                            <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-xs">Draft</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">{{ $p->is_featured ? '⭐' : '' }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse">
                            <form method="POST" action="{{ route('store.products.destroy', $p->id) }}" onsubmit="return confirm('Hapus Produk Store ini? (SKU/produk di Inventory tidak terhapus)')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                            <a href="{{ route('store.products.edit', $p->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Belum ada Produk Store. Klik "+ Tambah Produk" untuk menampilkan produk di website.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $products->links() }}</div>
@endsection
