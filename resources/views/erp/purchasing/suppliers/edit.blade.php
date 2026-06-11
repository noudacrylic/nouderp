@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Edit Pemasok — {{ $supplier->name }}</h1>


<form method="POST" action="{{ route('purchasing.suppliers.update', $supplier->id) }}">
    @method('PUT')
    @include('erp.purchasing.suppliers._form')
</form>
@endsection
