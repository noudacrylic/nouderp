@extends('layouts.erp')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-semibold">Tambah Produk Store</h1>
        <a href="{{ route('store.products.index') }}" class="text-sm text-gray-500 hover:underline">← Daftar</a>
    </div>

    <div class="bg-white rounded shadow p-5">
        <p class="text-sm text-gray-500 mb-4">Mulai dengan nama produk. Setelah disimpan sebagai draft, kamu bisa lengkapi detail, varian SKU, dan galeri foto/video dalam satu halaman.</p>

        <form method="POST" action="{{ route('store.products.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">Nama Tampil Web <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus
                       class="border rounded px-3 py-2 w-full" placeholder="mis. Akrilik Bening 3mm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Kategori</label>
                <select name="store_category_id" class="border rounded px-3 py-2 w-full">
                    <option value="">— Tanpa kategori —</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected((int) old('store_category_id') === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2 pt-1">
                <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Lanjut →</button>
                <a href="{{ route('store.products.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
