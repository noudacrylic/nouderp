{{--
    Tombol "Lapor Stok" untuk layar kerja divisi → popup pilih barang → laporan ke Purchasing.
    Param: $divisi (string) — nama divisi pelapor, mis. nama departemen produksi atau "Packing".
--}}
<div x-data="laporStok(@js($divisi))" class="inline-block" @keydown.escape.window="buka = false">
    <button type="button" @click="bukaPopup()"
            class="border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100 px-4 py-2 rounded-xl text-sm font-semibold transition">
        📦 Lapor Stok
    </button>

    <div x-show="buka" x-cloak class="fixed inset-0 z-[9999] flex items-start justify-center overflow-y-auto p-4 sm:pt-16" style="background-color: rgba(0,0,0,0.5);" @click.self="buka = false">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg text-left">
            <div class="flex items-center justify-between px-5 py-3 border-b">
                <div>
                    <div class="font-bold text-gray-800">📦 Lapor Stok</div>
                    <div class="text-xs text-gray-500">Barang habis / hampir habis di divisi <b x-text="divisi"></b> — diteruskan ke Purchasing.</div>
                </div>
                <button type="button" @click="buka = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
            </div>

            <form method="POST" action="{{ route('lapor-stok.simpan') }}" class="px-5 py-4 space-y-3" @submit="if (!pilih && !lainnya.trim()) { $event.preventDefault(); galat = 'Pilih barangnya, atau isi nama barang di Lainnya.'; }">
                @csrf
                <input type="hidden" name="divisi" :value="divisi">
                <input type="hidden" name="product_id" :value="pilih ? pilih.id : ''">

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Barang</label>
                    <template x-if="pilih">
                        <div class="flex items-center justify-between border border-amber-300 bg-amber-50 rounded px-3 py-2 text-sm">
                            <span><b x-text="pilih.nama"></b> <span class="text-xs text-gray-500" x-text="pilih.sku"></span></span>
                            <button type="button" @click="pilih = null" class="text-xs text-blue-600 hover:underline">Ganti</button>
                        </div>
                    </template>
                    <div x-show="!pilih">
                        <input type="text" x-model="cari" placeholder="Cari nama / SKU barang…" class="w-full border rounded px-3 py-2 text-sm" autocomplete="off">
                        <div class="mt-1 max-h-52 overflow-y-auto border rounded divide-y text-sm">
                            <div x-show="memuat" class="px-3 py-2 text-xs text-gray-400">Memuat…</div>
                            <template x-for="b in tersaring()" :key="b.id">
                                <button type="button" @click="pilih = b; lainnya = ''; galat = ''" class="w-full text-left px-3 py-1.5 hover:bg-amber-50 flex justify-between gap-2">
                                    <span x-text="b.nama"></span>
                                    <span class="text-[11px] text-gray-400 whitespace-nowrap" x-text="b.sku + ' · ' + b.jenis"></span>
                                </button>
                            </template>
                            <div x-show="!memuat && tersaring().length === 0" class="px-3 py-2 text-xs text-gray-400">Tidak ketemu — isi di "Lainnya" di bawah.</div>
                        </div>
                        <input type="text" name="nama_barang" x-model="lainnya" @input="galat = ''" maxlength="150"
                               placeholder="Lainnya: nama barang yang belum ada di daftar" class="mt-2 w-full border rounded px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Kondisi</label>
                    <div class="flex gap-2">
                        @foreach (\App\Modules\Purchasing\Models\LaporStok::KONDISI as $k => $label)
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="kondisi" value="{{ $k }}" class="peer sr-only" @checked($k === 'habis')>
                                <span class="block text-center border rounded px-3 py-2 text-sm font-semibold peer-checked:border-amber-500 peer-checked:bg-amber-100 peer-checked:text-amber-900">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <input type="text" name="catatan" maxlength="255" placeholder="Catatan (opsional)" class="col-span-2 border rounded px-3 py-2 text-sm">
                    <input type="text" name="pelapor_nama" maxlength="100" placeholder="Nama Anda (opsional)" class="col-span-2 border rounded px-3 py-2 text-sm">
                </div>

                <div x-show="galat" class="text-xs text-red-600" x-text="galat"></div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" @click="buka = false" class="border border-gray-300 text-gray-600 px-4 py-2 rounded text-sm">Batal</button>
                    <button class="bg-amber-500 hover:bg-amber-600 text-white px-5 py-2 rounded text-sm font-semibold">Kirim Laporan</button>
                </div>
            </form>

            <div class="px-5 pb-4" x-show="laporan.length">
                <div class="text-xs font-semibold text-gray-600 mb-1 border-t pt-3">Laporan divisi ini (14 hari)</div>
                <div class="divide-y text-xs max-h-48 overflow-y-auto">
                    <template x-for="(l, i) in laporan" :key="i">
                        <div class="py-1.5 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="font-medium text-gray-800 truncate" x-text="l.barang"></div>
                                <div class="text-[11px] text-gray-400" x-text="l.waktu + ' · ' + l.kondisi + ' · ' + l.pelapor"></div>
                            </div>
                            <span class="shrink-0 px-2 py-0.5 rounded font-semibold" :class="l.status[1]" x-text="l.status[0]"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

@once
<script>
function laporStok(divisi) {
    return {
        divisi, buka: false, memuat: false, barang: [], laporan: [], cari: '', pilih: null, lainnya: '', galat: '',
        bukaPopup() {
            this.buka = true;
            this.memuat = true;
            fetch(@js(route('lapor-stok.pilihan')) + '?divisi=' + encodeURIComponent(this.divisi), { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => { this.barang = d.barang || []; this.laporan = d.laporan || []; })
                .catch(() => { this.galat = 'Daftar barang gagal dimuat — isi nama barang di "Lainnya".'; })
                .finally(() => { this.memuat = false; });
        },
        tersaring() {
            const q = this.cari.trim().toLowerCase();
            const hasil = q ? this.barang.filter(b => (b.nama + ' ' + b.sku).toLowerCase().includes(q)) : this.barang;
            return hasil.slice(0, 60);
        },
    };
}
</script>
@endonce
