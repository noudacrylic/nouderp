@extends('layouts.erp')

@section('content')
{{-- Editor WYSIWYG Trix (CDN, sama pola dgn Alpine di ERP). --}}
<link rel="stylesheet" href="https://unpkg.com/trix@2.1.15/dist/trix.css">
<style>
    trix-editor { min-height: 320px; background: #fff; }
    trix-editor:empty:not(:focus)::before { color: #9ca3af; }
    trix-toolbar .trix-button-group { border-color: #e5e7eb; }
</style>

<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">Edit Artikel</h1>
        <p class="text-xs text-gray-500">Slug: /blog/{{ $article->slug }}</p>
    </div>
    <a href="{{ route('store.articles.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Kembali</a>
</div>

<form method="POST" action="{{ route('store.articles.update', $article->id) }}" enctype="multipart/form-data" id="articleForm">
    @csrf @method('PUT')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Kolom utama --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded shadow p-4">
                <label class="block text-xs text-gray-500 mb-1">Judul <span class="text-red-500">*</span></label>
                <input type="text" name="title" value="{{ old('title', $article->title) }}" required
                       class="border rounded px-3 py-2 w-full text-lg font-semibold">

                <label class="block text-xs text-gray-500 mb-1 mt-4">Ringkasan (excerpt)</label>
                <textarea name="excerpt" rows="2" maxlength="500"
                          class="border rounded px-3 py-2 w-full text-sm"
                          placeholder="1-2 kalimat ringkas — tampil di daftar blog & hasil pencarian.">{{ old('excerpt', $article->excerpt) }}</textarea>
            </div>

            <div class="bg-white rounded shadow p-4">
                <label class="block text-xs text-gray-500 mb-2">Isi Artikel</label>
                <input id="articleContent" type="hidden" name="content" value="{{ old('content', $article->content) }}">
                <trix-editor input="articleContent" class="border rounded"></trix-editor>
                <p class="text-xs text-gray-400 mt-2">Sisipkan gambar via tombol lampiran editor — otomatis terunggah ke penyimpanan media.</p>
            </div>
        </div>

        {{-- Sidebar pengaturan --}}
        <div class="space-y-4">
            <div class="bg-white rounded shadow p-4 space-y-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kategori</label>
                    <select name="store_article_category_id" class="border rounded px-2 py-1.5 w-full">
                        <option value="">— Tanpa kategori —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected((int) old('store_article_category_id', $article->store_article_category_id) === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Penulis</label>
                    <input type="text" name="author" value="{{ old('author', $article->author) }}" class="border rounded px-2 py-1.5 w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Slug (URL)</label>
                    <input type="text" name="slug" value="{{ old('slug', $article->slug) }}" class="border rounded px-2 py-1.5 w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Jadwal Terbit</label>
                    <input type="datetime-local" name="published_at"
                           value="{{ old('published_at', $article->published_at?->format('Y-m-d\TH:i')) }}"
                           class="border rounded px-2 py-1.5 w-full">
                    <p class="text-xs text-gray-400 mt-1">Kosongkan = terbit sekarang saat "Terbitkan". Isi tanggal depan = terjadwal.</p>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $article->is_featured)) class="w-4 h-4">
                    Jadikan unggulan
                </label>
            </div>

            {{-- Cover --}}
            <div class="bg-white rounded shadow p-4">
                <label class="block text-xs text-gray-500 mb-2">Gambar Cover</label>
                @if($article->cover_url)
                    <img src="{{ $article->cover_url }}" alt="cover" class="w-full h-40 object-cover rounded mb-2">
                @endif
                <input type="file" name="cover" accept="image/*" class="text-sm w-full">
                <p class="text-xs text-gray-400 mt-1">Rasio landscape disarankan. Maks 5MB.</p>
            </div>

            {{-- SEO --}}
            <div class="bg-white rounded shadow p-4 space-y-3">
                <div class="text-xs font-semibold text-gray-600 uppercase tracking-wide">SEO</div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Meta Title</label>
                    <input type="text" name="meta_title" value="{{ old('meta_title', $article->meta_title) }}"
                           class="border rounded px-2 py-1.5 w-full" placeholder="default: judul artikel">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Meta Description</label>
                    <textarea name="meta_description" rows="2" maxlength="255"
                              class="border rounded px-2 py-1.5 w-full text-sm" placeholder="default: ringkasan">{{ old('meta_description', $article->meta_description) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    {{-- Aksi (pola Noud: 2 tombol status) --}}
    <div class="sticky bottom-0 mt-4 bg-white border-t shadow-inner px-4 py-3 flex items-center gap-2 rounded-b">
        <button type="submit" name="action" value="publish"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded font-semibold text-sm">
            ✓ Terbitkan
        </button>
        <button type="submit" name="action" value="draft"
                class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2.5 rounded font-semibold text-sm">
            Simpan sebagai Draft
        </button>
        @if($article->isLive())
            <a href="#" class="ml-auto text-xs text-emerald-600">● Sedang tayang</a>
        @endif
    </div>
</form>

@push('scripts')
<script src="https://unpkg.com/trix@2.1.15/dist/trix.umd.min.js"></script>
<script>
    // Upload gambar inline editor → endpoint artikel → sisipkan URL publik (R2/lokal).
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const uploadUrl = @json(route('store.articles.image', $article->id));

        addEventListener('trix-attachment-add', function (event) {
            const attachment = event.attachment;
            if (!attachment.file) return;

            const form = new FormData();
            form.append('file', attachment.file);

            fetch(uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: form,
            })
                .then((r) => r.ok ? r.json() : Promise.reject(r))
                .then((data) => attachment.setAttributes({ url: data.url, href: data.url }))
                .catch(() => { alert('Gagal mengunggah gambar.'); attachment.remove(); });
        });
    })();
</script>
@endpush
@endsection
