@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Transfer — {{ $transfer->transfer_number }}</h1>
    <a href="{{ route('fixed-assets.transfers.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
</div>

<form method="POST" action="{{ route('fixed-assets.transfers.update', $transfer->id) }}">
    @csrf @method('PUT')
    @include('erp.fixed-assets.transfers._form')
</form>
@endsection
