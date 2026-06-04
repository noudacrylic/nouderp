@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">+ Disposisi Aset</h1>
    <a href="{{ route('fixed-assets.disposals.index') }}" class="text-gray-500 text-sm underline">← Kembali</a>
</div>
@if($errors->any()) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">@foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<form method="POST" action="{{ route('fixed-assets.disposals.store') }}">
    @csrf
    @include('erp.fixed-assets.disposals._form')
</form>
@endsection
