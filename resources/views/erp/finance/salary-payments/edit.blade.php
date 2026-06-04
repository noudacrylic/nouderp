@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Bayar Gaji — {{ $sp->number }}</h1>
    <a href="{{ route('finance.cash-bank.salary-payments.index') }}" class="text-sm text-gray-600 hover:underline">← Kembali</a>
</div>

@include('erp.finance.salary-payments._form')
@endsection
