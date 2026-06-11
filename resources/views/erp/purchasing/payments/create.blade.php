@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Tambah Pembayaran Pemasok</h1>


<form method="POST" action="{{ route('purchasing.payments.store') }}">
    @include('erp.purchasing.payments._form')
</form>
@endsection
