{{-- Galeri Instansi: foto klien/instansi yang pernah memesan produk ini.
     Tampil di halaman produk web, di bawah deskripsi (bukti sosial).
     Dikelola via AJAX, di luar <form> utama — sama seperti galeri produk. --}}
<div class="bg-white rounded shadow p-4 mt-6 max-w-5xl"
     x-data="storeShowcaseGallery({{ $product->id }}, {{ Js::from($product->media->where('group', 'showcase')->map(fn($m) => [
        'id' => $m->id, 'url' => $m->url, 'alt_text' => $m->alt_text,
        'caption' => $m->caption, 'sort_order' => $m->sort_order,
     ])->values()) }})">

    <h2 class="text-sm font-semibold text-gray-700 border-b pb-2 mb-1">Galeri Instansi (Bukti Sosial)</h2>
    <p class="text-xs text-gray-500 mb-3">
        Foto instansi/klien yang pernah memesan produk ini. Tampil di halaman produk web
        <span class="font-medium">di bawah deskripsi</span>. Isi keterangan dengan nama instansinya —
        itu yang paling menambah kepercayaan calon pembeli.
    </p>

    <div class="flex flex-wrap gap-3 items-end mb-4 text-sm">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tambah Foto (bisa banyak)</label>
            <input type="file" accept="image/*" multiple @change="uploadImages($event)" :disabled="busy"
                   class="text-xs">
        </div>
        <span x-show="busy" x-cloak class="text-xs text-blue-600">Mengunggah…</span>
    </div>
    <p x-show="error" x-cloak class="text-xs text-red-600 mb-3" x-text="error"></p>

    <div x-show="!items.length" x-cloak class="text-xs text-gray-400 italic">Belum ada foto instansi.</div>

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
        <template x-for="(m, idx) in items" :key="m.id">
            <div class="border rounded overflow-hidden relative">
                <div class="aspect-square bg-gray-100 flex items-center justify-center overflow-hidden">
                    <img :src="m.url" class="object-cover w-full h-full" alt="">
                </div>

                {{-- Keterangan = nama instansi (tampil di web) --}}
                <div class="px-1.5 pt-1.5">
                    <input type="text" x-model="m.caption" @change="saveCaption(m)"
                           placeholder="Nama instansi (tampil di web)"
                           class="border rounded px-1.5 py-1 w-full text-[11px]">
                </div>
                {{-- Alt text = kata kunci SEO (tidak tampil) --}}
                <div class="px-1.5 pt-1">
                    <input type="text" x-model="m.alt_text" @change="saveAlt(m)"
                           placeholder="Alt / kata kunci SEO"
                           class="border rounded px-1.5 py-1 w-full text-[11px]"
                           title="Deskripsi gambar untuk SEO & aksesibilitas">
                </div>

                <div class="p-1.5 flex items-center justify-between gap-1 text-[11px]">
                    <div class="flex gap-1">
                        <button type="button" @click="move(idx, -1)" :disabled="idx === 0"
                                class="px-1.5 py-0.5 bg-gray-100 rounded disabled:opacity-40" title="Naik">↑</button>
                        <button type="button" @click="move(idx, 1)" :disabled="idx === items.length - 1"
                                class="px-1.5 py-0.5 bg-gray-100 rounded disabled:opacity-40" title="Turun">↓</button>
                    </div>
                    <button type="button" @click="remove(m)"
                            class="px-1.5 py-0.5 bg-red-50 text-red-600 rounded">Hapus</button>
                </div>
            </div>
        </template>
    </div>
</div>

@push('scripts')
<script>
function storeShowcaseGallery(productId, initial) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const base = `/erp/store/products/${productId}/media`;
    return {
        items: initial || [],
        busy: false,
        error: '',
        async send(url, method, body) {
            this.error = '';
            const r = await fetch(url, {
                method,
                headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: body ? JSON.stringify(body) : null,
            });
            if (!r.ok) { const j = await r.json().catch(() => ({})); throw new Error(j.message || 'Gagal'); }
            return r.json();
        },
        async uploadImages(e) {
            const files = e.target.files; if (!files.length) return;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('group', 'showcase');
                for (const f of files) fd.append('images[]', f);
                const r = await fetch(base, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                    body: fd,
                });
                if (!r.ok) { const j = await r.json().catch(() => ({})); throw new Error(j.message || 'Gagal mengunggah'); }
                const res = await r.json();
                this.items.push(...res.media);
            } catch (err) { this.error = err.message; }
            finally { this.busy = false; e.target.value = ''; }
        },
        async saveCaption(m) {
            try { await this.send(`${base}/${m.id}/caption`, 'PUT', { caption: m.caption || '' }); }
            catch (err) { this.error = err.message; }
        },
        async saveAlt(m) {
            try { await this.send(`${base}/${m.id}/alt`, 'PUT', { alt_text: m.alt_text || '' }); }
            catch (err) { this.error = err.message; }
        },
        async remove(m) {
            if (!confirm('Hapus foto instansi ini?')) return;
            try {
                await this.send(`${base}/${m.id}`, 'DELETE');
                this.items = this.items.filter(it => it.id !== m.id);
            } catch (err) { this.error = err.message; }
        },
        async move(idx, dir) {
            const j = idx + dir;
            if (j < 0 || j >= this.items.length) return;
            const tmp = this.items[idx]; this.items[idx] = this.items[j]; this.items[j] = tmp;
            try {
                await this.send(`${base}/reorder`, 'PUT', { order: this.items.map(it => it.id) });
            } catch (err) { this.error = err.message; }
        },
    };
}
</script>
@endpush
