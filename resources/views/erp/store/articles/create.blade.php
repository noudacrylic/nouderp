@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Tulis Artikel Baru</h1>
</div>

<div class="bg-white rounded shadow p-4 max-w-2xl">
    <p class="text-sm text-gray-500 mb-4">Mulai dengan judul. Setelah draft dibuat, Anda bisa menulis isi, unggah cover, atur SEO, dan terbitkan.</p>
    <form method="POST" action="{{ route('store.articles.store') }}">
        @csrf
        <label class="block text-xs text-gray-500 mb-1">Judul Artikel <span class="text-red-500">*</span></label>
        <input type="text" name="title" value="{{ old('title') }}" required autofocus
               class="border rounded px-3 py-2 w-full" placeholder="mis. 5 Ide Kreatif Kerajinan Akrilik untuk Dekorasi Rumah">
        <div class="flex gap-2 mt-5">
            <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Buat Draft &amp; Lanjut</button>
            <a href="{{ route('store.articles.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
        </div>
    </form>
</div>
@endsection
