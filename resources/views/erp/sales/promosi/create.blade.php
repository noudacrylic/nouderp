@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Tambah Promo</h1>
    <a href="{{ route('sales.promosi.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
</div>

@include('erp.sales.promosi._form', ['action' => route('sales.promosi.store'), 'method' => 'POST'])
@endsection
