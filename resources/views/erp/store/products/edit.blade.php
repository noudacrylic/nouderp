@extends('layouts.erp')

@section('content')
<div class="max-w-7xl mx-auto flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Produk Store</h1>
    <a href="{{ route('store.products.index') }}" class="text-sm text-gray-500 hover:underline">← Daftar</a>
</div>

<form method="POST" action="{{ route('store.products.update', $product->id) }}">
    @method('PUT')
    @include('erp.store.products._form')
</form>
@endsection
