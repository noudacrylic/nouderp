@extends('layouts.erp')

@section('content')
<h1 class="text-lg font-semibold mb-4">Edit Pembayaran — {{ $payment->payment_number }}</h1>


<form method="POST" action="{{ route('purchasing.payments.update', $payment->id) }}">
    @method('PUT')
    @include('erp.purchasing.payments._form')
</form>
@endsection
