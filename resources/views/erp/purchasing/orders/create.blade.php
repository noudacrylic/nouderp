@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Tambah Purchase Order</h1>


<form method="POST" action="{{ route('purchasing.orders.store') }}">
    @include('erp.purchasing.orders._form')
</form>
@endsection
