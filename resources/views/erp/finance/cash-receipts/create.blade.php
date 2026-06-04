@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pemasukan Baru</h1>
</div>

<form method="POST" action="{{ route('finance.cash-bank.receipts.store') }}">
    @csrf
    @include('erp.finance.cash-receipts._form')
</form>
@endsection
