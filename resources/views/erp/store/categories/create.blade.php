@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Tambah Kategori Store</h1>
</div>

<div class="bg-white rounded shadow p-4 max-w-3xl">
    <form method="POST" action="{{ route('store.categories.store') }}">
        @include('erp.store.categories._form')
    </form>
</div>
@endsection
