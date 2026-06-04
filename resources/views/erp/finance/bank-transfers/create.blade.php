@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Transfer Antar Bank Baru</h1>
</div>

<form method="POST" action="{{ route('finance.cash-bank.transfers.store') }}">
    @csrf
    @include('erp.finance.bank-transfers._form')
</form>
@endsection
