@extends('layouts.erp')

@section('content')

<div class="max-w-4xl mx-auto">

    <div class="mb-6">
        <a href="{{ route('customers.edit', $customer->id) }}" class="text-sm text-blue-600 hover:underline">&larr; Kembali ke {{ $customer->name }}</a>
        <h1 class="text-lg font-semibold mt-2">Tambah Cabang</h1>
    </div>

    <form method="POST" action="{{ route('customers.branches.store', $customer->id) }}">

        @include('erp.master.customers.branches._form', ['cabang' => null])

        <div class="mt-6 flex items-center gap-3">
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Simpan Cabang</button>
            <a href="{{ route('customers.edit', $customer->id) }}" class="text-sm text-gray-500 hover:underline">Batal</a>
        </div>

    </form>

</div>

@endsection
