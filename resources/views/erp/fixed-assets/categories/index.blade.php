@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Kategori Aset Tetap</h1>
    <a href="{{ route('fixed-assets.categories.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Kategori</a>
</div>

@if(session('success')) <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded mb-3 text-sm">{{ session('success') }}</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Kode / nama..." class="border rounded px-2 py-1.5 w-64">
    </div>
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Kode</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Akun Aset</th>
                <th class="px-3 py-2 text-left">Akun Akumulasi</th>
                <th class="px-3 py-2 text-left">Akun Beban Dep</th>
                <th class="px-3 py-2 text-right">Umur Default</th>
                <th class="px-3 py-2 text-center">Disusutkan?</th>
                <th class="px-3 py-2 text-center">Aktif</th>
                <th class="px-3 py-2 text-right w-44">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($categories as $cat)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 font-medium">{{ $cat->code }}</td>
                    <td class="px-3 py-2">{{ $cat->name }}</td>
                    <td class="px-3 py-2 text-xs">{{ $cat->fixedAssetAccount->code ?? '-' }} {{ $cat->fixedAssetAccount->name ?? '' }}</td>
                    <td class="px-3 py-2 text-xs">{{ $cat->accumulatedDepreciationAccount->code ?? '-' }}</td>
                    <td class="px-3 py-2 text-xs">{{ $cat->depreciationExpenseAccount->code ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ $cat->default_useful_life_months }}</td>
                    <td class="px-3 py-2 text-center">{{ $cat->is_depreciable_default ? 'Ya' : 'Tidak' }}</td>
                    <td class="px-3 py-2 text-center">{{ $cat->is_active ? 'Aktif' : 'Non' }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse">
                            <form method="POST" action="{{ route('fixed-assets.categories.destroy', $cat->id) }}" onsubmit="return confirm('Hapus kategori?')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                            <a href="{{ route('fixed-assets.categories.edit', $cat->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">Belum ada kategori.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $categories->links() }}</div>
@endsection
