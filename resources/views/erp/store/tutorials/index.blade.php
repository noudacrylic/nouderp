@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Tutorial Pemasangan</h1>
    <a href="{{ route('store.tutorials.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tutorial Baru</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ $q }}" placeholder="Kode / judul / slug..." class="border rounded px-2 py-1.5 w-64">
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
                <th class="px-3 py-2 text-left w-24">Kode</th>
                <th class="px-3 py-2 text-left">Judul</th>
                <th class="px-3 py-2 text-center w-20">Produk</th>
                <th class="px-3 py-2 text-right w-24">Scan</th>
                <th class="px-3 py-2 text-right w-24">Kunjungan</th>
                <th class="px-3 py-2 text-center w-24">Status</th>
                <th class="px-3 py-2 text-right w-48">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tutorials as $t)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2">
                        <span class="font-mono font-bold">{{ $t->code }}</span>
                    </td>
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $t->title }}</div>
                        <div class="text-xs text-gray-400">{{ $t->shortUrl() }}</div>
                    </td>
                    <td class="px-3 py-2 text-center">{{ $t->products_count }}</td>
                    {{-- Scan = datang dari stiker fisik; kunjungan = total termasuk Google. --}}
                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format($t->scan_count, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ number_format($t->view_count, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($t->status === 'published')
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-emerald-100 text-emerald-700">Terbit</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-500">Draft</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse">
                            <form method="POST" action="{{ route('store.tutorials.destroy', $t->id) }}" onsubmit="return confirm('Hapus tutorial ini? Stiker yang sudah tercetak dengan kode {{ $t->code }} akan menjadi buntu.')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                            <a href="{{ route('store.tutorials.edit', $t->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Edit</a>
                            <a href="{{ route('store.tutorials.qr', $t->id) }}" class="border border-gray-300 text-gray-700 px-2 py-1 rounded text-xs hover:bg-gray-50">QR</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Belum ada tutorial.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $tutorials->links() }}</div>
@endsection
