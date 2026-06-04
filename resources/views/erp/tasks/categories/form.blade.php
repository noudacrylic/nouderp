@extends('layouts.erp')

@section('content')
@php
    $isEdit = $mode === 'edit';
    $action = $isEdit ? route('tasks.categories.update', $category->id) : route('tasks.categories.store');
    $title  = $isEdit ? 'Edit Kategori' : 'Tambah Kategori';
@endphp

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $title }}</h1>
    <a href="{{ route('tasks.categories.index') }}" class="text-sm text-gray-500">← Kembali</a>
</div>

@if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded mb-3 text-sm">
        <ul class="list-disc pl-5">@foreach ($errors->all() as $err) <li>{{ $err }}</li> @endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="bg-white rounded shadow p-4 space-y-4 max-w-lg">
    @csrf
    @if($isEdit) @method('PATCH') @endif

    <div>
        <label class="block text-xs text-gray-500 mb-1">Nama *</label>
        <input type="text" name="name" value="{{ old('name', $category->name) }}" required
               class="w-full border rounded px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Warna</label>
        <div class="flex gap-2 items-center">
            <input type="color" name="color" value="{{ old('color', $category->color ?: '#94a3b8') }}"
                   class="w-12 h-9 border rounded cursor-pointer">
            <span class="text-xs text-gray-500">Pilih warna untuk badge kategori di Board</span>
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true))>
        Aktif
    </label>

    <div class="flex gap-2 pt-2 border-t">
        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-semibold">💾 Simpan</button>
        <a href="{{ route('tasks.categories.index') }}" class="border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
    </div>
</form>
@endsection
