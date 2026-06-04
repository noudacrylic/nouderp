@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Transfer {{ $bt->number }}</h1>
</div>

<form method="POST" action="{{ route('finance.cash-bank.transfers.update', $bt->id) }}">
    @csrf
    @method('PUT')
    @include('erp.finance.bank-transfers._form')
</form>
@endsection
