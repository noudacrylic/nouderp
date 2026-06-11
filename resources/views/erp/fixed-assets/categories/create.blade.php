@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">+ Tambah Kategori Aset</h1>
    <a href="{{ route('fixed-assets.categories.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
</div>
<form method="POST" action="{{ route('fixed-assets.categories.store') }}">
    @csrf
    @include('erp.fixed-assets.categories._form')
</form>
@endsection
