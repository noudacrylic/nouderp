@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto flex items-center justify-between mb-6">
    <h1 class="text-lg font-semibold">Edit Mesin — {{ $machine->name }}</h1>
    <form method="POST" action="{{ route('sdm.mesin.destroy', $machine->id) }}"
          onsubmit="return confirm('Hapus mesin ini? Log scan akan ikut terhapus.')">
        @csrf @method('DELETE')
        <button class="border border-red-200 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded text-sm">Hapus</button>
    </form>
</div>
<form method="POST" action="{{ route('sdm.mesin.update', $machine->id) }}">
    @csrf @method('PUT')
    @include('erp.sdm.mesin._form')
</form>
@endsection
