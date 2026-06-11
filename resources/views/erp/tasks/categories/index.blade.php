@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Kategori Task</h1>
    <a href="{{ route('tasks.categories.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Kategori</a>
</div>


<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Warna</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-center">Aktif</th>
                <th class="px-3 py-2 text-center">Jumlah Task</th>
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($categories as $cat)
            <tr class="border-b">
                <td class="px-3 py-2">
                    <div class="w-5 h-5 rounded-full" style="background: {{ $cat->color }};"></div>
                </td>
                <td class="px-3 py-2 font-medium">{{ $cat->name }}</td>
                <td class="px-3 py-2 text-center">
                    @if($cat->is_active)
                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Aktif</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500">Nonaktif</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-center">{{ $cat->tasks()->count() }}</td>
                <td class="px-3 py-2 text-right">
                    <a href="{{ route('tasks.categories.edit', $cat->id) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                    <form method="POST" action="{{ route('tasks.categories.destroy', $cat->id) }}" class="inline" onsubmit="return confirm('Hapus kategori ini? Task yang terkait akan jadi tanpa kategori.')">
                        @csrf @method('DELETE')
                        <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada kategori.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
