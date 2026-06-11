@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Kasbon <span class="font-mono text-sm text-gray-500">{{ $kasbon->code }}</span></h1>
    <a href="{{ route('sdm.kasbon.show', $kasbon->id) }}" class="text-gray-600 text-sm">← Kembali</a>
</div>


<form method="POST" action="{{ route('sdm.kasbon.update', $kasbon->id) }}" class="bg-white rounded shadow p-4 max-w-3xl">
    @method('PUT')
    @include('erp.sdm.kasbon._form', ['kasbon' => $kasbon])
    <div class="mt-4 flex justify-end gap-2">
        <a href="{{ route('sdm.kasbon.show', $kasbon->id) }}" class="px-3 py-1.5 border rounded text-sm text-gray-700">Batal</a>
        <button class="px-4 py-1.5 bg-emerald-600 text-white rounded text-sm">Simpan</button>
    </div>
</form>
@endsection
