@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pengaturan Task Manager</h1>
</div>

@if(session('success'))
    <div class="mb-3 bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm">{{ session('success') }}</div>
@endif

<form method="POST" action="{{ route('tasks.settings.update') }}" class="bg-white rounded shadow p-4 space-y-4 max-w-2xl">
    @csrf @method('PATCH')

    <div>
        <div class="text-sm font-semibold text-gray-800 mb-2">Otomasi Cetak</div>
        <p class="text-xs text-gray-500 mb-3">
            Saat Sales Order dibuat & memuat produk dengan flag <em>Butuh Printing</em>,
            task baru akan otomatis dibuat dan ditujukan ke user di bawah ini.
            Flag per-produk bisa diatur di <a href="/erp/inventory/products" class="text-blue-600 hover:underline">Inventory → Produk</a>.
        </p>

        <label class="block text-xs text-gray-500 mb-1">User Default untuk Task Printing</label>
        <select name="printing_default_user_id" class="w-full border rounded px-2 py-2 text-sm max-w-sm">
            <option value="">— Tidak ditentukan (otomasi nonaktif) —</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" @selected($setting->printing_default_user_id == $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex gap-2 pt-2 border-t">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-semibold">💾 Simpan</button>
    </div>
</form>
@endsection
