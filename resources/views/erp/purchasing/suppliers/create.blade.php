@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Tambah Pemasok</h1>


<form method="POST" action="{{ route('purchasing.suppliers.store') }}">
    @include('erp.purchasing.suppliers._form')
</form>
@endsection
