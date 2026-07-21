@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Blog / Artikel</h1>
    <a href="{{ route('store.articles.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tulis Artikel</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ $q }}" placeholder="Judul / slug..." class="border rounded px-2 py-1.5 w-64">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft' => 'Draft', 'published' => 'Terbit'] as $val => $label)
                <option value="{{ $val }}" @selected(request('status') === $val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    @include('erp._partials.per-page-select')
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Judul</th>
                <th class="px-3 py-2 text-left">Kategori</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-left">Terbit</th>
                <th class="px-3 py-2 text-right w-36">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($articles as $a)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $a->title }}</div>
                        <div class="text-xs text-gray-400">{{ $a->slug }}</div>
                    </td>
                    <td class="px-3 py-2">{{ $a->category->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $scheduled = $a->status === 'published' && $a->published_at && $a->published_at->isFuture();
                        @endphp
                        @if($scheduled)
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-700">Terjadwal</span>
                        @elseif($a->status === 'published')
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-700">Terbit</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-500">Draft</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $a->published_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse">
                            <form method="POST" action="{{ route('store.articles.destroy', $a->id) }}" onsubmit="return confirm('Hapus artikel ini?')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                            <a href="{{ route('store.articles.edit', $a->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Belum ada artikel.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $articles->links() }}</div>
@endsection
