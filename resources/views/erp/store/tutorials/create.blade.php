@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Tutorial Baru</h1>
</div>

<div class="bg-white rounded shadow p-4 max-w-2xl">
    <p class="text-sm text-gray-500 mb-4">Mulai dengan kode &amp; judul. Setelah draft dibuat, Anda bisa mengisi video, menulis langkah bergambar, memilih produk terkait, lalu menerbitkan.</p>

    <form method="POST" action="{{ route('store.tutorials.store') }}">
        @csrf

        <label class="block text-xs text-gray-500 mb-1">Kode <span class="text-red-500">*</span></label>
        <input type="text" name="code" value="{{ old('code') }}" required autofocus maxlength="16"
               class="border rounded px-3 py-2 w-40 font-mono @error('code') border-red-400 @enderror"
               placeholder="tb1">
        @error('code')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror

        {{-- Peringatan ini sengaja tegas: kode inilah satu-satunya bagian sistem
             yang tidak bisa diperbaiki setelah stikernya menempel di barang orang. --}}
        <div class="mt-2 rounded bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800 leading-relaxed">
            Kode ini <b>dicetak di stiker produk</b> dan menjadi alamat
            <span class="font-mono">{{ rtrim(config('store.storefront_url'), '/') }}/t/<b>tb1</b></span>.
            Begitu stikernya tertempel dan barangnya dikirim, kode tidak bisa diubah lagi —
            jadi pakai penanda keluarga produk yang tidak akan berganti, misalnya
            <span class="font-mono">tb1</span>–<span class="font-mono">tb5</span> untuk tempat brosur,
            <span class="font-mono">kn1</span>–<span class="font-mono">kn3</span> untuk kartu nama.
            Judul, video, dan produk terkaitnya boleh diganti kapan saja tanpa mengganggu kode.
        </div>

        <label class="block text-xs text-gray-500 mb-1 mt-4">Judul <span class="text-red-500">*</span></label>
        <input type="text" name="title" value="{{ old('title') }}" required
               class="border rounded px-3 py-2 w-full @error('title') border-red-400 @enderror"
               placeholder="mis. Cara Memasang Tempat Brosur Akrilik A4 3 Susun">
        @error('title')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror

        <div class="flex gap-2 mt-5">
            <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Buat Draft &amp; Lanjut</button>
            <a href="{{ route('store.tutorials.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
        </div>
    </form>
</div>
@endsection
