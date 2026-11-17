{{-- Jendela "Gambar belum dilampirkan" (kartu tahap foto): pilih berkas dari Kotak Porto
     (unggahan HP), potong/putar, simpan → dipindah ke folder porto pesanan di NAS & kartu selesai.

     Pratinjau potong dibuat server (tegak + sudah diputar), potongan dikirim sebagai pecahan
     0–1 terhadap pratinjau itu — lihat GambarEditor. --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">

@php [$rw, $rh] = config('galeri.rasio_porto', [4, 5]); @endphp
<div x-data="portoLampir({ urlDaftar: @js(route('tasks.meja.porto.daftar')), rasio: {{ $rw }} / {{ $rh }} })" x-cloak
     @buka-porto.window="buka($event.detail)" @keydown.escape.window="tutupEdit() || (buka_ = false)">
    <div x-show="buka_" class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b">
                <div>
                    <div class="font-bold text-sm">🖼 Lampirkan foto — <span x-text="judul"></span></div>
                    <div class="text-[11px] text-gray-500">Dari Kotak Porto (menu Porto di HP). Klik untuk memilih; urutan pilih = urutan simpan, foto pertama jadi foto utama. Semua foto dibuat {{ $rw }}:{{ $rh }} — tanpa edit dipotong tengah seperti terlihat di kotak.</div>
                </div>
                <button type="button" @click="buka_ = false" class="text-2xl leading-none text-gray-400 hover:text-gray-700">×</button>
            </div>

            {{-- Daftar kotak --}}
            <div x-show="!edit" class="flex-1 overflow-y-auto p-4">
                <p x-show="muat" class="text-sm text-gray-400">Memuat…</p>
                <p x-show="!muat && !items.length" class="text-sm text-gray-500">Kotak Porto kosong. Unggah fotonya dulu dari HP: PWA → menu <b>Porto</b>.</p>
                <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                    <template x-for="it in items" :key="it.id">
                        <div class="relative rounded overflow-hidden border-2 cursor-pointer"
                             :class="urut(it.id) ? 'border-orange-500' : 'border-transparent hover:border-gray-300'" @click="toggle(it)">
                            {{-- Kotak berasio porto: tanpa edit = potong tengah (persis hasil server);
                                 sudah diedit = hasil potong/putar sebenarnya. --}}
                            <div class="bg-gray-100" style="aspect-ratio: {{ $rw }} / {{ $rh }}">
                                <img :src="pilihan[it.id]?.hasil || it.thumb" alt="" loading="lazy" class="w-full h-full object-cover">
                            </div>
                            <span x-show="it.jenis === 'video'" class="absolute bottom-6 left-1 bg-black/60 text-white text-[10px] font-bold rounded px-1.5">▶ VIDEO</span>
                            <span x-show="urut(it.id)" class="absolute top-1 left-1 bg-orange-500 text-white text-[11px] font-bold rounded-full w-5 h-5 flex items-center justify-center" x-text="urut(it.id)"></span>
                            <span x-show="pilihan[it.id]?.hasil" class="absolute top-1 right-1 bg-orange-500 text-white text-[10px] font-semibold rounded px-1">✂ diedit</span>
                            <div class="px-1.5 py-1 text-[10px] text-gray-500 truncate" x-text="(it.oleh || '—') + ' · ' + it.waktu"></div>
                            <button type="button" x-show="urut(it.id) && it.bisa_edit" @click.stop="mulaiEdit(it)"
                                    class="absolute bottom-6 right-1 bg-white/95 border text-[11px] font-semibold rounded px-1.5 py-0.5 hover:bg-orange-50">✂ Edit</button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Editor potong & putar --}}
            <div x-show="edit" class="flex-1 min-h-0 flex flex-col p-4 gap-2">
                <div class="flex-1 min-h-0 bg-gray-900 rounded overflow-hidden" style="height: 60vh">
                    <img x-ref="gambar" alt="" class="block max-w-full">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="putar(-90)" class="border rounded px-2 py-1 text-xs font-semibold hover:bg-gray-50">⟲ Putar kiri</button>
                    <button type="button" @click="putar(90)" class="border rounded px-2 py-1 text-xs font-semibold hover:bg-gray-50">⟳ Putar kanan</button>
                    <button type="button" @click="aturUlang()" class="border rounded px-2 py-1 text-xs hover:bg-gray-50">Atur ulang (tengah)</button>
                    <span class="text-[11px] text-gray-500">Rasio dikunci {{ $rw }}:{{ $rh }} — geser & ubah ukuran kotaknya.</span>
                    <span class="flex-1"></span>
                    <button type="button" @click="tutupEdit()" class="border rounded px-3 py-1 text-xs">Batal</button>
                    <button type="button" @click="pakaiEdit()" class="bg-orange-500 text-white rounded px-3 py-1 text-xs font-semibold">Pakai</button>
                </div>
            </div>

            <div x-show="!edit" class="flex items-center justify-between gap-2 px-4 py-3 border-t">
                <p class="text-xs" :class="galat ? 'text-red-600 font-semibold' : 'text-gray-500'"
                   x-text="galat || (urutan.length + ' dipilih · asli tetap disimpan di subfolder asli bila diedit')"></p>
                <button type="button" @click="simpan()" :disabled="!urutan.length || menyimpan"
                        class="bg-green-600 text-white rounded px-4 py-1.5 text-sm font-semibold disabled:opacity-40"
                        x-text="menyimpan ? 'Memindah ke NAS…' : '✓ Simpan & selesai'"></button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('.porto-buka');
    if (!b) return;
    window.dispatchEvent(new CustomEvent('buka-porto', { detail: { url: b.dataset.url, judul: b.dataset.judul } }));
});

window.portoLampir = function (cfg) {
    return {
        buka_: false, judul: '', url: '', items: [], muat: false, galat: '', menyimpan: false,
        pilihan: {},   // id → {putar, potong}
        urutan: [],    // id sesuai urutan dipilih
        edit: null,    // {it, putar}
        cropper: null,

        async buka(d) {
            this.url = d.url; this.judul = d.judul; this.buka_ = true;
            this.pilihan = {}; this.urutan = []; this.galat = ''; this.edit = null;
            this.muat = true;
            try {
                var r = await fetch(cfg.urlDaftar, { headers: { 'Accept': 'application/json' } });
                this.items = (await r.json()).items || [];
            } catch (e) {
                this.galat = 'Daftar gagal dimuat.';
            }
            this.muat = false;
        },

        urut(id) { var i = this.urutan.indexOf(id); return i < 0 ? 0 : i + 1; },

        toggle(it) {
            if (this.urut(it.id)) {
                this.urutan = this.urutan.filter(function (x) { return x !== it.id; });
                delete this.pilihan[it.id];
            } else {
                this.urutan.push(it.id);
                this.pilihan[it.id] = { putar: 0, potong: null };
            }
        },

        // ───── Editor ─────
        mulaiEdit(it) {
            var p = this.pilihan[it.id];
            this.edit = { it: it, putar: p.putar, potong: p.potong };
            this.$nextTick(() => this.pasang());
        },
        pasang() {
            var self = this, img = this.$refs.gambar, potong = this.edit.potong;
            if (this.cropper) { this.cropper.destroy(); this.cropper = null; }
            img.src = this.edit.it.pratinjau + '?putar=' + this.edit.putar;
            img.onload = function () {
                self.cropper = new Cropper(img, {
                    viewMode: 1, autoCropArea: 1, aspectRatio: cfg.rasio, checkOrientation: false, rotatable: false, zoomable: false,
                    ready: function () {
                        if (!potong) return;
                        var d = self.cropper.getImageData();
                        self.cropper.setData({ x: potong.x * d.naturalWidth, y: potong.y * d.naturalHeight,
                                               width: potong.w * d.naturalWidth, height: potong.h * d.naturalHeight });
                    },
                });
            };
        },
        putar(arah) {
            this.edit.putar = ((this.edit.putar + arah) % 360 + 360) % 360;
            this.edit.potong = null; // potongan lama tak berlaku di orientasi baru
            this.pasang();
        },
        aturUlang() { if (this.cropper) this.cropper.reset(); },
        pakaiEdit() {
            var d = this.cropper.getData(true), im = this.cropper.getImageData();
            var w = im.naturalWidth, h = im.naturalHeight;
            // Pratinjau hasil = potongan dari gambar yang sudah diputar, sama dengan yang dibuat server.
            var kanvas = this.cropper.getCroppedCanvas({ maxWidth: 480, maxHeight: 600 });
            this.pilihan[this.edit.it.id] = {
                putar: this.edit.putar,
                potong: { x: d.x / w, y: d.y / h, w: d.width / w, h: d.height / h },
                hasil: kanvas.toDataURL('image/jpeg', 0.85),
            };
            this.tutupEdit();
        },
        tutupEdit() {
            if (!this.edit) return false;
            if (this.cropper) { this.cropper.destroy(); this.cropper = null; }
            this.edit = null;
            return true;
        },

        async simpan() {
            this.menyimpan = true; this.galat = '';
            var self = this;
            try {
                var r = await fetch(this.url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                               'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ pilihan: this.urutan.map(function (id) {
                        return { id: id, putar: self.pilihan[id].putar, potong: self.pilihan[id].potong };
                    }) }),
                });
                var j = {};
                try { j = await r.json(); } catch (e) {}
                if (!r.ok) throw new Error(j.pesan || j.message || ('Galat ' + r.status));
                if (window.toast) window.toast(j.pesan);
                window.location.reload();
            } catch (e) {
                this.galat = e.message;
                this.menyimpan = false;
            }
        },
    };
};
</script>
@endpush
