{{-- Galeri media Produk Store (foto + video). Dikelola via AJAX, di luar <form> utama. --}}
<div class="bg-white rounded shadow p-4 mt-6 max-w-5xl"
     x-data="storeMediaGallery({{ $product->id }}, {{ Js::from($product->media->map(fn($m) => [
        'id' => $m->id, 'kind' => $m->kind, 'source' => $m->source,
        'url' => $m->url, 'alt_text' => $m->alt_text, 'is_primary' => (bool) $m->is_primary, 'sort_order' => $m->sort_order,
     ])->values()) }})">

    <h2 class="text-sm font-semibold text-gray-700 border-b pb-2 mb-3">Galeri Foto &amp; Video</h2>

    {{-- Aksi tambah --}}
    <div class="flex flex-wrap gap-3 items-end mb-4 text-sm">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tambah Foto (bisa banyak)</label>
            <input type="file" accept="image/*" multiple @change="uploadImages($event)" :disabled="busy"
                   class="text-xs">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tambah Video (file)</label>
            <input type="file" accept="video/*" @change="uploadVideo($event)" :disabled="busy"
                   class="text-xs">
        </div>
        <div class="flex-1 min-w-[240px]">
            <label class="block text-xs text-gray-500 mb-1">Atau link YouTube</label>
            <div class="flex gap-2">
                <input type="text" x-model="ytUrl" placeholder="https://youtu.be/..."
                       class="border rounded px-2 py-1.5 flex-1">
                <button type="button" @click="addYoutube()" :disabled="busy || !ytUrl"
                        class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm disabled:opacity-50">Tambah</button>
            </div>
        </div>
        <span x-show="busy" x-cloak class="text-xs text-blue-600">Mengunggah…</span>
    </div>
    <p x-show="error" x-cloak class="text-xs text-red-600 mb-3" x-text="error"></p>

    {{-- Daftar media --}}
    <div x-show="!items.length" x-cloak class="text-xs text-gray-400 italic">Belum ada media.</div>

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
        <template x-for="(m, idx) in items" :key="m.id">
            <div class="border rounded overflow-hidden relative group">
                {{-- Preview --}}
                <div class="aspect-square bg-gray-100 flex items-center justify-center overflow-hidden">
                    <template x-if="m.kind === 'image'">
                        <img :src="m.url" class="object-cover w-full h-full" alt="">
                    </template>
                    <template x-if="m.kind === 'video' && m.source === 'youtube'">
                        <img :src="ytThumb(m.url)" class="object-cover w-full h-full" alt="">
                    </template>
                    <template x-if="m.kind === 'video' && m.source !== 'youtube'">
                        <video :src="m.url" class="object-cover w-full h-full" muted></video>
                    </template>
                </div>

                {{-- Badge --}}
                <div class="absolute top-1 left-1 flex gap-1">
                    <span x-show="m.is_primary" class="bg-green-600 text-white text-[10px] px-1.5 py-0.5 rounded">Utama</span>
                    <span x-show="m.kind === 'video'" class="bg-black/60 text-white text-[10px] px-1.5 py-0.5 rounded"
                          x-text="m.source === 'youtube' ? 'YouTube' : 'Video'"></span>
                </div>

                {{-- Alt text (kata kunci SEO) --}}
                <div class="px-1.5 pt-1.5">
                    <input type="text" x-model="m.alt_text" @change="saveAlt(m)"
                           placeholder="Alt / kata kunci SEO"
                           class="border rounded px-1.5 py-1 w-full text-[11px]" title="Deskripsi gambar untuk SEO & aksesibilitas">
                </div>

                {{-- Aksi --}}
                <div class="p-1.5 flex items-center justify-between gap-1 text-[11px]">
                    <div class="flex gap-1">
                        <button type="button" @click="move(idx, -1)" :disabled="idx === 0"
                                class="px-1.5 py-0.5 bg-gray-100 rounded disabled:opacity-40" title="Naik">↑</button>
                        <button type="button" @click="move(idx, 1)" :disabled="idx === items.length - 1"
                                class="px-1.5 py-0.5 bg-gray-100 rounded disabled:opacity-40" title="Turun">↓</button>
                    </div>
                    <div class="flex gap-1">
                        <button type="button" x-show="m.kind === 'image' && !m.is_primary" @click="setPrimary(m)"
                                class="px-1.5 py-0.5 bg-blue-50 text-blue-700 rounded">Jadikan utama</button>
                        <button type="button" @click="remove(m)"
                                class="px-1.5 py-0.5 bg-red-50 text-red-600 rounded">Hapus</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

@push('scripts')
<script>
function storeMediaGallery(productId, initial) {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const base = `/erp/store/products/${productId}/media`;
    return {
        items: initial || [],
        ytUrl: '',
        busy: false,
        error: '',
        async post(url, body, isForm) {
            this.error = '';
            const opts = { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } };
            if (isForm) { opts.body = body; }
            else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
            const r = await fetch(url, opts);
            if (!r.ok) { const j = await r.json().catch(() => ({})); throw new Error(j.message || 'Gagal mengunggah'); }
            return r.json();
        },
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
        async saveAlt(m) {
            try { await this.send(`${base}/${m.id}/alt`, 'PUT', { alt_text: m.alt_text || '' }); }
            catch (err) { this.error = err.message; }
        },
        async uploadImages(e) {
            const files = e.target.files; if (!files.length) return;
            this.busy = true;
            try {
                const fd = new FormData();
                for (const f of files) fd.append('images[]', f);
                const res = await this.post(base, fd, true);
                this.items.push(...res.media);
            } catch (err) { this.error = err.message; }
            finally { this.busy = false; e.target.value = ''; }
        },
        async uploadVideo(e) {
            const file = e.target.files[0]; if (!file) return;
            this.busy = true;
            try {
                const fd = new FormData(); fd.append('video', file);
                const res = await this.post(`${base}/video`, fd, true);
                this.items.push(res.media);
            } catch (err) { this.error = err.message; }
            finally { this.busy = false; e.target.value = ''; }
        },
        async addYoutube() {
            if (!this.ytUrl) return;
            this.busy = true;
            try {
                const res = await this.post(`${base}/youtube`, { url: this.ytUrl }, false);
                this.items.push(res.media);
                this.ytUrl = '';
            } catch (err) { this.error = err.message; }
            finally { this.busy = false; }
        },
        async setPrimary(m) {
            try {
                await this.send(`${base}/${m.id}/primary`, 'PUT');
                this.items.forEach(it => it.is_primary = (it.id === m.id && it.kind === 'image'));
            } catch (err) { this.error = err.message; }
        },
        async remove(m) {
            if (!confirm('Hapus media ini?')) return;
            try {
                await this.send(`${base}/${m.id}`, 'DELETE');
                const wasPrimary = m.is_primary;
                this.items = this.items.filter(it => it.id !== m.id);
                if (wasPrimary) {
                    const firstImg = this.items.find(it => it.kind === 'image');
                    if (firstImg) firstImg.is_primary = true;
                }
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
        ytThumb(url) {
            const id = this.ytId(url);
            return id ? `https://img.youtube.com/vi/${id}/hqdefault.jpg` : '';
        },
        ytId(url) {
            const m = (url || '').match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([\w-]{11})/);
            return m ? m[1] : '';
        },
    };
}
</script>
@endpush
