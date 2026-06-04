@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Pelunasan <span class="font-mono text-sm text-gray-500">{{ $kp->code }}</span></h1>
    <a href="{{ route('sdm.kasbon-pembayaran.show', $kp->id) }}" class="text-gray-600 text-sm">← Kembali</a>
</div>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>
@endif

<form method="POST" action="{{ route('sdm.kasbon-pembayaran.update', $kp->id) }}" class="bg-white rounded shadow p-4 max-w-3xl">
    @method('PUT')
    @include('erp.sdm.kasbon-pembayaran._form', ['kp' => $kp, 'selectedKasbon' => $kp->kasbon])
    <div class="mt-4 flex justify-end gap-2">
        <a href="{{ route('sdm.kasbon-pembayaran.show', $kp->id) }}" class="px-3 py-1.5 border rounded text-sm text-gray-700">Batal</a>
        <button class="px-4 py-1.5 bg-emerald-600 text-white rounded text-sm">Simpan</button>
    </div>
</form>
@endsection
