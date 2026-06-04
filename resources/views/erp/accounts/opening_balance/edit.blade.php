@extends('layouts.erp')

@section('content')
@php
    $currentAmount = $mainLine->debit > 0 ? (float) $mainLine->debit : (float) $mainLine->credit;
@endphp
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Edit Saldo Awal — {{ $mainLine->account->name ?? 'Unknown' }}</h1>
    <a href="{{ route('accounts.opening-balance.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← Kembali</a>
</div>

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2 rounded mb-3">
        @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
    </div>
@endif
@if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2 rounded mb-3">{{ session('error') }}</div>
@endif

<div class="bg-white rounded shadow p-4 max-w-2xl">
    <p class="text-xs text-gray-500 mb-4">
        Ubah nilai langsung — jurnal saldo awal akan ditimpa. Akun tidak bisa diubah; untuk pindah akun,
        hapus entry ini dan buat yang baru.
    </p>

    <form method="POST" action="{{ route('accounts.opening-balance.update', $journal->id) }}" class="space-y-4 text-sm">
        @csrf @method('PUT')

        <div>
            <label class="block text-xs text-gray-600 mb-1">Akun</label>
            <div class="border rounded px-2 py-1.5 bg-gray-50 text-gray-700">
                <span class="font-medium">{{ $mainLine->account->name ?? 'Unknown' }}</span>
                <span class="text-xs text-gray-500">— {{ $mainLine->account->code ?? '' }}</span>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Saldo (Rp)</label>
                <input type="number" step="0.01" min="0.01" name="amount" required
                       value="{{ old('amount', $currentAmount) }}"
                       class="border rounded px-2 py-1.5 w-full text-right">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Tanggal</label>
                <input type="date" name="date" required
                       value="{{ old('date', \Carbon\Carbon::parse($journal->date)->format('Y-m-d')) }}"
                       class="border rounded px-2 py-1.5 w-full">
            </div>
        </div>

        <div>
            <label class="block text-xs text-gray-600 mb-1">Deskripsi (opsional)</label>
            <textarea name="description" rows="2"
                      class="border rounded px-2 py-1.5 w-full">{{ old('description', $journal->description) }}</textarea>
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                Simpan Perubahan
            </button>
        </div>
    </form>

    <hr class="my-4">
    <form method="POST" action="{{ route('accounts.opening-balance.destroy', $journal->id) }}"
          onsubmit="return confirm('Hapus saldo awal {{ $mainLine->account->name ?? '' }}?')">
        @csrf @method('DELETE')
        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-sm">
            Hapus Saldo Awal
        </button>
    </form>
</div>
@endsection
