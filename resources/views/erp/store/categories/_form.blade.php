@csrf

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Nama Kategori <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $category->name ?? '') }}" required
               class="border rounded px-2 py-1.5 w-full" placeholder="mis. Akrilik Lembaran">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Slug (URL)</label>
        <input type="text" name="slug" value="{{ old('slug', $category->slug ?? '') }}"
               class="border rounded px-2 py-1.5 w-full" placeholder="otomatis dari nama bila kosong">
        <p class="text-xs text-gray-400 mt-1">Hanya huruf, angka, strip. Dipakai di URL: /kategori/<i>slug</i></p>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kategori Induk</label>
        <select name="parent_id" class="border rounded px-2 py-1.5 w-full">
            <option value="">— Tidak ada (kategori utama) —</option>
            @foreach($parents as $p)
                <option value="{{ $p->id }}" @selected((int) old('parent_id', $category->parent_id ?? 0) === $p->id)>{{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Urutan</label>
        <input type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}"
               class="border rounded px-2 py-1.5 w-32">
    </div>
</div>

<div class="mt-4">
    <label class="block text-xs text-gray-500 mb-1">Deskripsi Kategori</label>
    <textarea id="category-description" name="description" rows="4" maxlength="2000"
              class="border rounded px-2 py-1.5 w-full"
              placeholder="mis. Box charger akrilik untuk sekolah, kantor, dan masjid. Tersedia 5 sampai 20 kotak, bisa custom logo instansi.">{{ old('description', $category->description ?? '') }}</textarea>
    @include('erp._partials.description-preview', ['target' => 'category-description'])
    <p class="text-xs text-gray-400 mt-1">
        Tampil sebagai paragraf pengantar di halaman <i>/kategori/{{ $category->slug ?? 'slug' }}</i> toko online,
        sekaligus jadi deskripsi meta di hasil pencarian Google. Isi 1&ndash;2 kalimat yang memakai kata kunci
        yang dicari pembeli.
    </p>
</div>

<div class="flex gap-2 mt-5">
    <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan</button>
    <a href="{{ route('store.categories.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm">Batal</a>
</div>
