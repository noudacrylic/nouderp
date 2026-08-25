@extends('layouts.erp')

@section('content')
@include('erp._partials.trix-styles')

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Edit Tutorial</h1>
        <p class="text-xs text-gray-500">
            Cetak: <span class="font-mono">{{ $tutorial->shortUrl() }}</span>
            &nbsp;·&nbsp; Halaman: <span class="font-mono">/tutorial/{{ $tutorial->slug }}</span>
        </p>
    </div>
    <div class="flex gap-2 items-center">
        <a href="{{ route('store.tutorials.qr', $tutorial->id) }}" class="border border-gray-300 text-gray-700 px-3 py-1.5 rounded text-sm hover:bg-gray-50">QR Stiker</a>
        <a href="{{ route('store.tutorials.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Kembali</a>
    </div>
</div>

<form method="POST" action="{{ route('store.tutorials.update', $tutorial->id) }}" id="tutorialForm">
    @csrf @method('PUT')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Kolom utama --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded shadow p-4">
                <label class="block text-xs text-gray-500 mb-1">Judul <span class="text-red-500">*</span></label>
                <input type="text" name="title" value="{{ old('title', $tutorial->title) }}" required
                       class="border rounded px-3 py-2 w-full text-lg font-semibold">

                <label class="block text-xs text-gray-500 mb-1 mt-4">Video YouTube</label>
                <input type="text" name="youtube" value="{{ old('youtube', $tutorial->youtube_id) }}"
                       class="border rounded px-3 py-2 w-full text-sm font-mono @error('youtube') border-red-400 @enderror"
                       placeholder="Tempel URL YouTube apa pun — watch?v=, youtu.be, embed, atau ID-nya">
                @error('youtube')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                @if($tutorial->youtube_id)
                    <div class="mt-2 flex items-center gap-3">
                        <img src="{{ $tutorial->thumbnailUrl() }}" alt="" class="w-32 rounded border">
                        <a href="https://youtu.be/{{ $tutorial->youtube_id }}" target="_blank" rel="noopener"
                           class="text-xs text-blue-600 hover:underline">Buka di YouTube ↗</a>
                    </div>
                @endif

                <label class="block text-xs text-gray-500 mb-1 mt-4">Deskripsi singkat</label>
                <textarea name="description" rows="3" maxlength="1000"
                          class="border rounded px-3 py-2 w-full text-sm"
                          placeholder="1-3 kalimat. Tampil di bawah video dan dipakai sebagai ringkasan di hasil pencarian.">{{ old('description', $tutorial->description) }}</textarea>
            </div>

            <div class="bg-white rounded shadow p-4">
                <label class="block text-xs text-gray-500 mb-2">Langkah Bergambar</label>
                <input id="tutorialContent" type="hidden" name="content" value="{{ trix_content(old('content', $tutorial->content)) }}">
                <trix-editor input="tutorialContent" class="border rounded"></trix-editor>
                <p class="text-xs text-gray-400 mt-2">Sisipkan gambar via tombol lampiran editor — otomatis terunggah &amp; diperkecil.
                    <b>Klik gambarnya lalu ketik keterangan singkat</b> (mis. &ldquo;Pasang bagian bawah ke slot depan&rdquo;) — keterangan itu tampil di bawah gambar sekaligus menjadi <span class="font-mono">alt</span>, yang dibaca Google Gambar dan pembaca layar. Boleh dikosongkan, tapi gambarnya tak akan ditemukan lewat pencarian gambar.</p>

                {{-- Video tutorial Noud tanpa narasi, jadi mesin pencari tidak bisa
                     membaca isinya sama sekali. Bagian inilah satu-satunya yang
                     bisa dibaca Google — karena itu diberi penjelasan, bukan
                     dibiarkan tampak sebagai kolom tambahan yang boleh dilewati. --}}
                <div class="mt-3 rounded bg-blue-50 border border-blue-200 px-3 py-2 text-xs text-blue-800 leading-relaxed">
                    Video tanpa suara tidak bisa dibaca Google. Tulisan di kolom inilah yang membuat
                    halaman ini muncul di pencarian seperti <i>"cara memasang tempat brosur akrilik"</i> —
                    pencarian yang dilakukan orang <b>sebelum</b> membeli. Boleh dikosongkan dulu dan
                    diisi belakangan, mulai dari tutorial yang paling sering di-scan.
                </div>
            </div>
        </div>

        {{-- Sidebar pengaturan --}}
        <div class="space-y-4">
            <div class="bg-white rounded shadow p-4 space-y-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kode (tercetak di stiker)</label>
                    <input type="text" name="code" value="{{ old('code', $tutorial->code) }}" required maxlength="16"
                           class="border rounded px-2 py-1.5 w-full font-mono @error('code') border-red-400 @enderror">
                    @error('code')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                    <p class="text-[11px] text-amber-700 mt-1 leading-snug">
                        Jangan diubah bila stikernya sudah tercetak — barang yang beredar akan buntu.
                    </p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Slug halaman</label>
                    <input type="text" name="slug" value="{{ old('slug', $tutorial->slug) }}"
                           class="border rounded px-2 py-1.5 w-full text-sm">
                    <p class="text-[11px] text-gray-400 mt-1 leading-snug">Boleh diganti kapan saja — kode di atas yang menjaga stiker lama tetap hidup.</p>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Urutan</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $tutorial->sort_order) }}"
                           class="border rounded px-2 py-1.5 w-full text-sm">
                </div>
            </div>

            <div class="bg-white rounded shadow p-4">
                <label class="block text-xs text-gray-500 mb-2">Produk terkait</label>
                @php
                    $selected = collect(old('products', $tutorial->products->pluck('id')->all()))->map(fn($v) => (int) $v)->all();
                    $grouped  = $products->groupBy(fn($p) => $p->category->name ?? 'Tanpa kategori');
                @endphp
                <div class="border rounded max-h-80 overflow-y-auto divide-y">
                    @foreach($grouped as $catName => $items)
                        <div class="px-2 py-1.5 bg-gray-50 text-[11px] font-semibold text-gray-500 uppercase tracking-wide sticky top-0">{{ $catName }}</div>
                        @foreach($items as $p)
                            <label class="flex items-start gap-2 px-2 py-1.5 text-sm hover:bg-blue-50 cursor-pointer">
                                <input type="checkbox" name="products[]" value="{{ $p->id }}" class="mt-0.5"
                                       @checked(in_array($p->id, $selected, true))>
                                <span>{{ $p->name }}</span>
                            </label>
                        @endforeach
                    @endforeach
                </div>
                <p class="text-[11px] text-gray-400 mt-2 leading-snug">
                    Tautannya dua arah: tutorial ini menampilkan produk-produk tersebut, dan tiap halaman
                    produk itu menampilkan tombol ke tutorial ini.
                </p>
            </div>

            <div class="bg-white rounded shadow p-4 space-y-3">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide">SEO</div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Meta title</label>
                    <input type="text" name="meta_title" value="{{ old('meta_title', $tutorial->meta_title) }}"
                           class="border rounded px-2 py-1.5 w-full text-sm" placeholder="Kosongkan = pakai judul">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Meta description</label>
                    <textarea name="meta_description" rows="2" maxlength="255"
                              class="border rounded px-2 py-1.5 w-full text-sm"
                              placeholder="Kosongkan = pakai deskripsi singkat">{{ old('meta_description', $tutorial->meta_description) }}</textarea>
                </div>
            </div>

            <div class="bg-white rounded shadow p-4">
                <div class="text-xs text-gray-500 mb-2">
                    Di-scan dari stiker <b class="text-gray-800">{{ number_format($tutorial->scan_count, 0, ',', '.') }}</b>×
                    &nbsp;·&nbsp; total kunjungan <b class="text-gray-800">{{ number_format($tutorial->view_count, 0, ',', '.') }}</b>×
                </div>
                <div class="flex gap-2">
                    <button name="action" value="publish" class="bg-emerald-600 text-white px-4 py-2 rounded text-sm flex-1">Terbitkan</button>
                    <button name="action" value="draft" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm">Draft</button>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
{{-- Setelan Trix HARUS mendahului pustakanya — lihat alasannya di partial;
     dibalik urutannya, tombol Heading berhenti bekerja tanpa pesan galat. --}}
@include('erp._partials.trix-config')
<script src="https://unpkg.com/trix@2.1.15/dist/trix.umd.min.js"></script>
@include('erp._partials.trix-upload', [
    'trixUploadUrl' => route('store.tutorials.image', $tutorial->id),
    'trixFormId'    => 'tutorialForm',
])
@endpush
@endsection
