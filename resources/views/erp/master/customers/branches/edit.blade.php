@extends('layouts.erp')

@section('content')

<div class="max-w-4xl mx-auto">

    <div class="mb-6">
        <a href="{{ route('customers.edit', $customer->id) }}" class="text-sm text-blue-600 hover:underline">&larr; Kembali ke {{ $customer->name }}</a>
        <h1 class="text-lg font-semibold mt-2">
            Edit Cabang
            @unless ($cabang->is_active)
                <span class="ml-2 text-xs font-normal bg-gray-100 text-gray-600 border border-gray-300 rounded px-2 py-0.5 align-middle">Diarsipkan</span>
            @endunless
        </h1>
    </div>

    <form method="POST" action="{{ route('customers.branches.update', [$customer->id, $cabang->id]) }}">
        @method('PUT')

        @include('erp.master.customers.branches._form')

        <div class="mt-6 flex items-center gap-3">
            <button class="bg-blue-600 text-white px-4 py-2 rounded">Simpan Perubahan</button>
            <a href="{{ route('customers.edit', $customer->id) }}" class="text-sm text-gray-500 hover:underline">Batal</a>
        </div>

    </form>

</div>

@endsection
