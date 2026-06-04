@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Transfer — {{ $transfer->transfer_number }}</h1>
    <a href="{{ route('fixed-assets.transfers.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
</div>
@if($errors->any()) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">@foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<form method="POST" action="{{ route('fixed-assets.transfers.update', $transfer->id) }}">
    @csrf @method('PUT')
    @include('erp.fixed-assets.transfers._form')
</form>
@endsection
