@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Pengeluaran {{ $cd->number }}</h1>
</div>

<form method="POST" action="{{ route('finance.cash-bank.disbursements.update', $cd->id) }}">
    @csrf
    @method('PUT')
    @include('erp.finance.cash-disbursements._form')
</form>
@endsection
