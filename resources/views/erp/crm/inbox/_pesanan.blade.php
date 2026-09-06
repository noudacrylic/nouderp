{{-- Penyusun Sales Order dari layar chat.

     Gaya Kasir: cari produk, isi jumlah, klik barisnya untuk memberi diskon.
     Bedanya Kasir menutup transaksi di tempat (langsung faktur), sedangkan di
     sini hasilnya DRAFT — pesanan yang lahir dari percakapan hampir selalu
     masih berubah sekali-dua kali sebelum disepakati, dan SO yang terlanjur
     di-post cuma bisa dibatalkan lewat void yang jejaknya menempel di buku
     selamanya.

     Isinya dibagi SEGMEN yang bisa dilipat. Di kolom selebar 380px, formulir
     yang terbuka semua menuntut gulir panjang untuk mengubah satu angka; yang
     terlipat menunjukkan ringkasan isinya di kepala segmen, jadi tak perlu
     dibuka hanya untuk memastikan.

     Ongkir tidak diketik di sini — ia datang dari tab Ongkir, dan kalau isi
     keranjang berubah setelahnya, segmen Ongkir menandai dirinya basi. --}}
@php
    $draftPesanan = (array) ($terpilih?->order_draft['pesanan'] ?? []);
@endphp

<div class="space-y-3 text-sm" x-data="pesananCrm()"
     @ongkir-dipilih.window="terimaDariOngkir($event.detail)">

    {{-- ============================================================= DAFTAR --}}
    {{-- Yang pertama dilihat saat tab dibuka adalah pesanan yang SEDANG
         berjalan, bukan formulir kosong. Pertanyaan yang paling sering datang
         lewat chat bukan "saya mau pesan" melainkan "pesanan saya sampai mana"
         — dan menjawabnya tidak boleh menuntut pindah menu. --}}
    <div x-show="mode === 'daftar'" class="space-y-2">

        <template x-for="p in pesanan" :key="p.id">
            <div class="rounded-lg border px-2 py-1.5"
                 :class="p.draft ? 'border-amber-300 bg-amber-50/60'
                                 : (p.selesai ? 'border-gray-200 opacity-70' : 'border-emerald-300 bg-emerald-50/50')">
                <div class="flex items-baseline justify-between gap-2">
                    <a :href="p.url" class="text-xs font-semibold truncate underline" x-text="p.nomor"></a>
                    <span class="shrink-0 text-xs font-bold" x-text="rupiah(p.total)"></span>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5">
                    <span class="text-[11px] font-semibold shrink-0"
                          :class="p.draft ? 'text-amber-700' : (p.selesai ? 'text-gray-500' : 'text-emerald-700')"
                          x-text="p.status"></span>
                    <span class="text-[11px] text-gray-500 truncate text-right" x-text="p.catatan"></span>
                </div>
                {{-- Barangnya ikut terlihat, gaya kartu pesanan marketplace:
                     pertanyaan lewat chat hampir selalu menyebut BARANGNYA
                     ("rak bolpoin saya gimana"), bukan nomor SO. --}}
                <div class="mt-1 pt-1 border-t border-gray-200/70 space-y-0.5">
                    <template x-for="(it, i) in p.items" :key="i">
                        <div class="flex items-baseline justify-between gap-2 text-[11px]">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-gray-700" x-text="it.nama"></span>
                                {{-- SKU di baris kedua, bukan disambung ke nama:
                                     nama produk di sini sudah dipotong ellipsis,
                                     dan SKU yang ikut terpotong tidak ada gunanya
                                     justru saat paling dibutuhkan. --}}
                                <span x-show="it.sku" class="block text-gray-400 font-mono" x-text="it.sku"></span>
                            </span>
                            <span class="shrink-0 text-gray-500" x-text="'×' + it.qty"></span>
                            <span class="shrink-0 text-gray-600 w-20 text-right" x-text="rupiah(it.total)"></span>
                        </div>
                    </template>
                    <div x-show="p.sisa_item" x-cloak class="text-[11px] text-gray-400"
                         x-text="'+' + p.sisa_item + ' barang lainnya'"></div>
                </div>

                <div class="flex items-baseline justify-between gap-2 mt-1 text-[11px] text-gray-500">
                    <span x-text="p.ambil ? 'Ambil di toko' : (p.kurir || 'Pengiriman')"></span>
                    <span x-show="!p.ambil && p.ongkir > 0" x-text="rupiah(p.ongkir)"></span>
                </div>

                <div class="flex items-center justify-between gap-2 mt-1">
                    <span class="text-[11px] text-gray-400" x-text="p.tanggal"></span>
                    <div class="flex items-center gap-2">
                        {{-- Menyalin ke kotak ketik, bukan mengirim: menjawab
                             "sudah sampai mana" hampir selalu perlu pengantar. --}}
                        <button type="button" @click="sisipKeChat(p)"
                                class="text-[11px] text-emerald-700 underline hover:no-underline">
                            Status
                        </button>
                        {{-- Pintu ke halaman SO, tempat SEMUA perubahan
                             dikerjakan — item, ongkir, kesepakatan, posting.
                             Satu tab (bukan tab baru): keranjang & alamat sudah
                             tersimpan di percakapan, jadi kembali ke chat ini
                             tidak kehilangan apa pun. --}}
                        <a :href="p.url"
                           class="text-[11px] font-semibold text-gray-700 border border-gray-300 rounded px-2 py-1 hover:bg-gray-50">
                            Buka SO
                        </a>
                        <button type="button" @click="kirimRincian(p)" :disabled="sibukRincian === p.id"
                                class="text-[11px] font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded px-2 py-1 disabled:opacity-60">
                            <span x-show="sibukRincian !== p.id">Rincian + Link Bayar</span>
                            <span x-show="sibukRincian === p.id" x-cloak>Menyiapkan…</span>
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <p x-show="!pesanan.length" x-cloak class="text-xs text-gray-500 px-1">
            @if($terpilih?->customer_id)
                Belum ada pesanan berjalan untuk pelanggan ini.
            @else
                Chat ini belum tertaut pelanggan. Pesanan pertama akan menautkannya.
            @endif
        </p>

        {{-- Galat ikut ditampilkan di tampilan daftar: tombol Rincian dipakai
             dari sini, dan kegagalannya tak boleh cuma terlihat di layar susun. --}}
        <div x-show="galat" x-cloak x-text="galat"
             class="rounded border border-red-300 bg-red-50 px-2 py-1.5 text-xs text-red-700"></div>

        <button type="button" @click="mode = 'buat'"
                class="w-full py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold">
            Buat Pesanan
        </button>
    </div>

    {{-- ============================================================== SUSUN --}}
    <div x-show="mode === 'buat'" x-cloak class="space-y-2">

        <button type="button" @click="mode = 'daftar'"
                class="text-[11px] text-gray-500 hover:text-gray-800">&larr; Daftar pesanan</button>

        {{-- ------------------------------------------------------ 1 pelanggan --}}
        <div class="rounded-lg border border-gray-200">
            <button type="button" @click="lipat('pelanggan')"
                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-left">
                <span class="text-[11px] font-bold text-gray-600 uppercase">1 · Pelanggan</span>
                <span class="ml-auto text-[11px] text-gray-500 truncate" x-show="!buka.pelanggan" x-text="ringkasPelanggan()"></span>
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 transition-transform duration-150"
                     :class="buka.pelanggan && 'rotate-180'"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>
            <div x-show="buka.pelanggan" x-cloak class="px-2 pb-2">
                @if($terpilih?->customer)
                    <div class="text-xs">
                        <div class="font-semibold truncate">{{ $terpilih->customer->name }}</div>
                        <div class="text-gray-500">{{ $terpilih->contact_key }}</div>
                    </div>
                @else
                    {{-- Chat dari nomor asing adalah keadaan NORMAL. Namanya
                         diketik operator supaya master pelanggan tidak terisi
                         nomor yang tak pernah jadi pesanan. --}}
                    <input type="text" x-model="namaPelanggan" placeholder="Nama pelanggan baru…"
                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">
                        Dibuat dengan nomor {{ $terpilih?->contact_key }} dan langsung ditautkan ke chat ini.
                    </p>
                @endif
            </div>
        </div>

        {{-- --------------------------------------------------------- 2 produk --}}
        <div class="rounded-lg border border-gray-200">
            <button type="button" @click="lipat('produk')"
                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-left">
                <span class="text-[11px] font-bold text-gray-600 uppercase">2 · Produk</span>
                <span class="ml-auto text-[11px] text-gray-500 truncate" x-show="!buka.produk" x-text="ringkasProduk()"></span>
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 transition-transform duration-150"
                     :class="buka.produk && 'rotate-180'"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>

            <div x-show="buka.produk" x-cloak class="px-2 pb-2 space-y-2">
                <div class="relative">
                    <input type="text" x-model="cari" @input.debounce.300ms="cariProduk()" @focus="cariProduk()"
                           placeholder="Cari nama atau SKU…"
                           class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">

                    <div x-show="hasil.length" x-cloak @click.outside="hasil = []"
                         class="absolute z-20 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto">
                        <template x-for="p in hasil" :key="p.id">
                            <button type="button" @click="tambah(p)"
                                    class="w-full text-left px-2 py-1.5 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                                <div class="text-xs font-medium truncate" x-text="p.name"></div>
                                <div class="text-[11px] text-gray-500">
                                    <span x-text="p.sku"></span>
                                    <span x-text="' · ' + rupiah(p.price)"></span>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>

                <template x-for="(b, i) in baris" :key="b.key">
                    <div class="rounded-lg border border-gray-200 px-2 py-1.5">
                        <div class="flex items-start gap-1">
                            {{-- Klik nama = buka harga & diskon baris. Disembunyikan
                                 sampai diminta karena 90% baris tidak memakainya, dan
                                 tiga kotak per baris membuat daftarnya tak terbaca. --}}
                            {{-- Panah ikut di sebelah nama, bukan di ujung baris:
                                 di ujung sana sudah ada qty, total, dan tombol
                                 buang — satu ikon lagi di situ jadi tidak jelas
                                 milik tombol mana. --}}
                            <button type="button" @click="b.buka = !b.buka" class="flex-1 min-w-0 text-left">
                                <div class="flex items-center gap-1">
                                    <svg class="w-3 h-3 shrink-0 text-gray-400 transition-transform duration-150"
                                         :class="b.buka && 'rotate-180'"
                                         fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                                    </svg>
                                    <span class="text-xs font-medium truncate" x-text="b.nama"></span>
                                </div>
                                <div x-show="b.sku" class="text-[11px] text-gray-400 font-mono truncate" x-text="b.sku"></div>
                                <div class="text-[11px] text-gray-500">
                                    <span x-text="rupiah(b.harga)"></span>
                                    <span x-show="b.diskonNilai > 0" class="text-emerald-700"
                                          x-text="' − ' + b.diskonNilai + (b.diskonJenis === 'percent' ? '%' : ' /pcs')"></span>
                                    <span x-show="b.promo && !b.diskonManual" class="text-purple-700"> · promo</span>
                                </div>
                            </button>
                            <input type="number" min="1" step="1" x-model.number="b.qty"
                                   class="w-12 shrink-0 border border-gray-300 rounded px-1 py-0.5 text-xs text-center">
                            <span class="w-20 shrink-0 text-right text-xs font-semibold" x-text="rupiah(totalBaris(b))"></span>
                            <button type="button" @click="baris.splice(i, 1)"
                                    class="shrink-0 text-gray-400 hover:text-red-600 px-0.5" title="Buang">&times;</button>
                        </div>

                        <div x-show="b.buka" x-cloak class="mt-1.5 pt-1.5 border-t border-gray-100 space-y-1.5">
                            {{-- Nama BOLEH ditimpa: pesanan custom disepakati lewat
                                 kalimat di chat ("box mahar 30×30 tutup emas"),
                                 sementara nama master produknya terlalu umum untuk
                                 dikenali pembeli di nota. Produknya sendiri tetap
                                 produk yang sama — stok & HPP-nya tidak ikut
                                 berubah hanya karena namanya ditulis ulang. --}}
                            <div>
                                <label class="block text-[10px] font-bold text-gray-400 uppercase mb-0.5">Nama di Nota</label>
                                <input type="text" x-model="b.nama" maxlength="255"
                                       class="w-full border border-gray-300 rounded px-1.5 py-1 text-xs">
                            </div>

                            <div class="flex items-end gap-1">
                                <div class="flex-1">
                                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-0.5">Harga</label>
                                    <input type="number" min="0" x-model.number="b.harga"
                                           class="w-full border border-gray-300 rounded px-1.5 py-1 text-xs">
                                </div>
                                <div class="flex-1">
                                    <label class="block text-[10px] font-bold text-gray-400 uppercase mb-0.5">Diskon</label>
                                    {{-- Diubah tangan = ditandai manual, dan promo
                                         berhenti menimpanya. Angka yang diketik
                                         operator adalah kesepakatan dengan pembeli;
                                         mesin promo tidak boleh membatalkannya. --}}
                                    <input type="number" min="0" x-model.number="b.diskonNilai"
                                           @input="b.diskonManual = true"
                                           class="w-full border border-gray-300 rounded px-1.5 py-1 text-xs">
                                </div>
                                {{-- Nominal = potongan PER UNIT, sama seperti form SO biasa. --}}
                                <select x-model="b.diskonJenis" @change="b.diskonManual = true"
                                        class="shrink-0 border border-gray-300 rounded px-1 py-1 text-xs bg-white">
                                    <option value="nominal">Rp/pcs</option>
                                    <option value="percent">%</option>
                                </select>
                            </div>

                            <div x-show="b.promo" x-cloak class="flex items-center justify-between gap-2">
                                <span class="text-[10px] text-purple-700 truncate" x-text="'Promo: ' + (b.promo || '')"></span>
                                <button type="button" x-show="b.diskonManual" @click="pakaiPromoBaris(b)"
                                        class="shrink-0 text-[10px] text-purple-700 underline hover:no-underline">
                                    Pakai promo
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <p x-show="!baris.length" x-cloak class="text-xs text-gray-400">
                    Belum ada produk. Cari di atas untuk menambahkan.
                </p>
            </div>
        </div>

        {{-- --------------------------------------------------------- 3 ongkir --}}
        <div class="rounded-lg border" :class="ongkirBasi() ? 'border-amber-300' : 'border-gray-200'">
            <button type="button" @click="lipat('ongkir')"
                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-left">
                <span class="text-[11px] font-bold text-gray-600 uppercase">3 · Ongkir</span>
                <span class="ml-auto text-[11px] truncate" x-show="!buka.ongkir"
                      :class="ongkirBasi() ? 'text-amber-700' : 'text-gray-500'"
                      x-text="ringkasOngkir()"></span>
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 transition-transform duration-150"
                     :class="buka.ongkir && 'rotate-180'"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>

            <div x-show="buka.ongkir" x-cloak class="px-2 pb-2 space-y-2">

                {{-- Metode kirim ditaruh PALING ATAS karena ia yang memutuskan
                     apakah sisa segmen ini perlu ada sama sekali. Barang yang
                     diambil di toko tidak punya ongkir, dan menyodorkan tombol
                     "Hitung Ongkir" untuknya cuma memancing tarif yang tak akan
                     pernah dipakai — lalu ikut tersimpan di SO. --}}
                <select x-model="metode" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white">
                    <option value="kurir">Kurir</option>
                    <option value="ambil_toko">Ambil di Toko</option>
                </select>

                <div x-show="metode === 'ambil_toko'" x-cloak class="text-xs text-gray-500">
                    Diambil di toko — tidak ada ongkir yang perlu dihitung.
                    <span x-show="ongkir" x-cloak class="block text-[11px] text-gray-400 mt-0.5">
                        Tarif yang sudah dihitung tetap tersimpan, dan dipakai lagi kalau metodenya diubah ke Kurir.
                    </span>
                </div>

                <div x-show="metode !== 'ambil_toko'" class="space-y-2">
                <template x-if="ongkir">
                    <div class="space-y-1.5">
                        <div class="text-xs">
                            <div class="flex items-center gap-1">
                                {{-- Instant ditandai sampai ke sini: harganya
                                     berlipat dan jendela ambilnya pendek, jadi
                                     operator harus tahu ia sedang menjanjikan
                                     kurir on-demand, bukan paket biasa. --}}
                                <span x-show="ongkir.instant" x-cloak
                                      class="shrink-0 text-[10px] font-bold px-1 py-0.5 rounded bg-orange-100 text-orange-700">
                                    INSTANT
                                </span>
                                <span class="font-medium truncate" x-text="ongkir.nama"></span>
                            </div>
                            <div class="text-gray-500" x-text="'Tarif ' + rupiah(ongkir.gross)"></div>
                        </div>

                        {{-- Potongan ongkir diputuskan DI SINI, bukan di tab Ongkir:
                             syaratnya hampir selalu bertingkat ("belanja di atas
                             300rb ongkir 10rb"), dan hanya layar ini yang tahu
                             berapa total belanjanya. --}}
                        <div class="flex items-end gap-1">
                            <div class="flex-1">
                                <label class="block text-[10px] font-bold text-gray-400 uppercase mb-0.5">Potongan Ongkir</label>
                                <input type="number" min="0" x-model.number="diskonOngkir" placeholder="0"
                                       @input="diskonOngkirManual = true"
                                       class="w-full border border-gray-300 rounded px-1.5 py-1 text-xs">
                            </div>
                            <select x-model="diskonOngkirJenis" @change="diskonOngkirManual = true"
                                    class="shrink-0 border border-gray-300 rounded px-1 py-1 text-xs bg-white">
                                <option value="nominal">Rp</option>
                                <option value="percent">%</option>
                            </select>
                        </div>

                        <div x-show="promoOngkir" x-cloak class="flex items-center justify-between gap-2">
                            <span class="text-[10px] text-purple-700 truncate" x-text="'Promo: ' + (promoOngkir || '')"></span>
                            <button type="button" x-show="diskonOngkirManual" @click="pakaiPromoOngkir()"
                                    class="shrink-0 text-[10px] text-purple-700 underline hover:no-underline">
                                Pakai promo
                            </button>
                        </div>

                        {{-- Total belanja ditampilkan berdampingan dengan potongan:
                             itulah angka yang menentukan tingkat promonya, dan
                             tanpa terlihat di sini operator harus menggulir ke
                             ringkasan untuk memeriksanya. --}}
                        <div class="flex justify-between text-[11px] text-gray-500 border-t border-gray-100 pt-1">
                            <span>Total belanja</span>
                            <span x-text="rupiah(subtotal() - diskonItem())"></span>
                        </div>
                        <div class="flex justify-between text-xs font-semibold">
                            <span>Ongkir dibayar</span>
                            <span x-text="rupiah(ongkirBersih())"></span>
                        </div>
                    </div>
                </template>

                <p x-show="!ongkir" x-cloak class="text-xs text-gray-400">Belum ada ongkir.</p>

                {{-- Keranjang berubah SETELAH ongkir dihitung = tarifnya untuk
                     berat yang sudah tidak berlaku. Tidak dihapus otomatis
                     (angka yang hilang sendiri lebih membingungkan), tapi
                     ditandai — dan pembuatan SO ditahan sampai diberesi. --}}
                <p x-show="ongkirBasi()" x-cloak
                   class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-1">
                    Isi keranjang berubah setelah ongkir ini dihitung — beratnya sudah lain.
                    Hitung ulang, atau buang ongkirnya.
                </p>

                <div class="flex items-center gap-1">
                    <button type="button" @click="keOngkir()"
                            class="flex-1 py-1.5 rounded-lg text-xs font-bold text-white"
                            :class="ongkirBasi() ? 'bg-amber-600 hover:bg-amber-700' : 'bg-blue-600 hover:bg-blue-700'"
                            x-text="ongkir ? 'Hitung Ulang Ongkir' : 'Hitung Ongkir'"></button>
                    <button type="button" x-show="ongkir" x-cloak @click="buangOngkir()"
                            class="shrink-0 px-2 py-1.5 rounded-lg border border-gray-300 text-xs text-gray-600 hover:bg-gray-50">
                        Buang
                    </button>
                </div>

                {{-- Batas yang harus terbaca, bukan cuma dipahami: panel ini
                     MENYUSUN pesanan baru dan tidak pernah mengubah pesanan yang
                     sudah jadi. Tanpa kalimat ini, operator yang punya dua
                     pesanan berjalan mengira menghitung ulang di sini akan
                     memperbarui ongkir salah satunya. --}}
                <p class="text-[11px] text-gray-400 border-t border-gray-100 pt-1.5">
                    Ongkir di sini hanya untuk pesanan yang sedang disusun.
                    Untuk mengubah pesanan yang sudah jadi, pakai <b>Buka SO</b> di daftar —
                    aturannya sama seperti SO lain (yang sudah di-post tidak bisa diubah).
                </p>
                </div>
            </div>
        </div>

        {{-- -------------------------------------------------- 4 catatan pembeli --}}
        <div class="rounded-lg border border-gray-200">
            <button type="button" @click="lipat('catatan')"
                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-left">
                <span class="text-[11px] font-bold text-gray-600 uppercase">4 · Catatan Pembeli</span>
                <span class="ml-auto text-[11px] text-gray-500 truncate" x-show="!buka.catatan"
                      x-text="catatan ? catatan : '—'"></span>
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 transition-transform duration-150"
                     :class="buka.catatan && 'rotate-180'"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>
            <div x-show="buka.catatan" x-cloak class="px-2 pb-2">
                <textarea x-model="catatan" rows="3" placeholder="Warna, ukuran, kesepakatan…"
                          class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm resize-none"></textarea>
            </div>
        </div>

        {{-- ------------------------------------------------------ 5 lain-lain --}}
        <div class="rounded-lg border border-gray-200">
            <button type="button" @click="lipat('lain')"
                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-left">
                <span class="text-[11px] font-bold text-gray-600 uppercase">5 · Lain-lain</span>
                <span class="ml-auto text-[11px] text-gray-500 truncate" x-show="!buka.lain" x-text="ringkasLain()"></span>
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 transition-transform duration-150"
                     :class="buka.lain && 'rotate-180'"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>

            <div x-show="buka.lain" x-cloak class="px-2 pb-2 space-y-2">
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-600 flex-1">Minimal DP</label>
                    <input type="number" min="0" max="100" x-model.number="minDp"
                           class="w-16 border border-gray-300 rounded-lg px-2 py-1 text-sm text-center">
                    <span class="text-xs text-gray-400">%</span>
                </div>

                {{-- Keep stock: pembeli setuju memesan barang yang stoknya belum
                     ada. Tanpa ini tautan pembayaran menolak bayar saat stok kurang. --}}
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" x-model="keepStok" class="mt-0.5 rounded border-gray-300">
                    <span>
                        <span class="block text-xs text-gray-700">Keep stock (stok menyusul)</span>
                        <span class="block text-[11px] text-gray-400">Boleh dibayar walau stok belum ada.</span>
                    </span>
                </label>

                {{-- Tempo: barang boleh dikirim sebelum dibayar. Termin boleh
                     kosong — tempo tanpa batas waktu tetap sah, yang hilang cuma
                     peringatan jatuh temponya. --}}
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" x-model="tempo" class="mt-0.5 rounded border-gray-300">
                    <span>
                        <span class="block text-xs text-gray-700">Pembayaran tempo</span>
                        <span class="block text-[11px] text-gray-400">Boleh dikirim sebelum dibayar.</span>
                    </span>
                </label>

                <div x-show="tempo" x-cloak class="flex items-center gap-2 pl-6">
                    <label class="text-xs text-gray-600 flex-1">Termin</label>
                    <input type="number" min="0" max="365" x-model.number="tempoHari" placeholder="—"
                           class="w-16 border border-gray-300 rounded-lg px-2 py-1 text-sm text-center">
                    <span class="text-xs text-gray-400">hari</span>
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------------ 6 ringkasan --}}
        <div class="rounded-lg border border-gray-200">
            <button type="button" @click="lipat('ringkas')"
                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 text-left">
                <span class="text-[11px] font-bold text-gray-600 uppercase">6 · Ringkasan</span>
                <span class="ml-auto text-[11px] font-bold text-gray-700" x-show="!buka.ringkas" x-text="rupiah(grand())"></span>
                <svg class="w-3.5 h-3.5 shrink-0 text-gray-400 transition-transform duration-150"
                     :class="buka.ringkas && 'rotate-180'"
                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/>
                </svg>
            </button>

            <div x-show="buka.ringkas" x-cloak class="px-2 pb-2 space-y-2">
                <div class="flex items-end gap-1">
                    <div class="flex-1">
                        <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Diskon Belanja</label>
                        <input type="number" min="0" x-model.number="diskonBelanja" placeholder="0"
                               @input="diskonBelanjaManual = true"
                               class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                    </div>
                    <select x-model="diskonBelanjaJenis" @change="diskonBelanjaManual = true"
                            class="shrink-0 border border-gray-300 rounded-lg px-1 py-1.5 text-sm bg-white">
                        <option value="nominal">Rp</option>
                        <option value="percent">%</option>
                    </select>
                </div>

                <div x-show="promoBelanja" x-cloak class="flex items-center justify-between gap-2">
                    <span class="text-[10px] text-purple-700 truncate" x-text="'Promo: ' + (promoBelanja || '')"></span>
                    <button type="button" x-show="diskonBelanjaManual" @click="pakaiPromoBelanja()"
                            class="shrink-0 text-[10px] text-purple-700 underline hover:no-underline">
                        Pakai promo
                    </button>
                </div>

                <div class="rounded-lg bg-gray-50 border border-gray-200 p-2 space-y-1 text-xs">
                    <div class="flex justify-between"><span class="text-gray-500">Subtotal</span>
                        <span x-text="rupiah(subtotal())"></span></div>
                    <div class="flex justify-between" x-show="diskonItem() > 0">
                        <span class="text-gray-500">Diskon produk</span>
                        <span class="text-emerald-700" x-text="'− ' + rupiah(diskonItem())"></span></div>
                    <div class="flex justify-between" x-show="diskonTotal() > 0">
                        <span class="text-gray-500">Diskon belanja</span>
                        <span class="text-emerald-700" x-text="'− ' + rupiah(diskonTotal())"></span></div>
                    <div class="flex justify-between" x-show="ongkir && metode !== 'ambil_toko'">
                        <span class="text-gray-500 truncate">Ongkir</span>
                        <span x-text="rupiah(ongkirBersih())"></span></div>
                    <div class="flex justify-between pt-1 border-t border-gray-200 text-sm font-bold">
                        <span>Total</span><span x-text="rupiah(grand())"></span></div>
                    <div class="flex justify-between text-gray-500" x-show="minDp">
                        <span x-text="'DP minimal ' + minDp + '%'"></span>
                        <span x-text="rupiah(nilaiDp())"></span></div>
                </div>
            </div>
        </div>

        <button type="button" @click="buat()" :disabled="sibuk"
                class="w-full py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold disabled:opacity-60">
            <span x-show="!sibuk">Buat SO Draft</span>
            <span x-show="sibuk" x-cloak>Menyimpan…</span>
        </button>

        <div x-show="galat" x-cloak x-text="galat"
             class="rounded border border-red-300 bg-red-50 px-2 py-1.5 text-xs text-red-700"></div>
    </div>
</div>

<script>
    function pesananCrm() {
        // Keranjang tersimpan di percakapan — ini sudah jadi bagian SO, jadi ia
        // tidak boleh lenyap hanya karena halaman dimuat ulang.
        const awal = @json($draftPesanan ?: new stdClass);

        return {
            namaPelanggan: awal.namaPelanggan ?? '',
            cari: '', hasil: [], baris: awal.baris ?? [], nomor: (awal.baris ?? []).length,
            diskonBelanja: awal.diskonBelanja ?? '',
            diskonBelanjaJenis: awal.diskonBelanjaJenis ?? 'nominal',
            diskonBelanjaManual: awal.diskonBelanjaManual ?? false,
            diskonOngkir: awal.diskonOngkir ?? '',
            diskonOngkirJenis: awal.diskonOngkirJenis ?? 'nominal',
            diskonOngkirManual: awal.diskonOngkirManual ?? false,
            // Nama promo yang sedang berlaku — dipakai hanya untuk ditampilkan.
            promoOngkir: null, promoBelanja: null, jamPromo: null,
            minDp: awal.minDp ?? 50,
            keepStok: awal.keepStok ?? false,
            tempo: awal.tempo ?? false,
            tempoHari: awal.tempoHari ?? '',
            metode: awal.metode ?? 'kurir',
            catatan: awal.catatan ?? '',
            ongkir: awal.ongkir ?? null,
            // Sidik keranjang saat ongkir dipasang — pembanding untuk tahu
            // tarifnya masih cocok atau sudah untuk berat yang lain.
            ongkirSidik: awal.ongkirSidik ?? '',
            sibuk: false, galat: '', jamSimpan: null, sibukRincian: null,

            // Daftar pesanan datang dari server, bukan dari draft: statusnya
            // berubah di luar layar ini (dibayar, masuk produksi, dikirim), jadi
            // salinan yang dibekukan di draft akan cepat berbohong.
            pesanan: @json($pesananTerkait ?? []),

            // Keranjang yang masih terisi berarti pekerjaan yang belum selesai;
            // membuka tab langsung ke daftar akan membuatnya terlihat hilang.
            mode: (awal.baris ?? []).length ? 'buat' : 'daftar',

            buka: { pelanggan: true, produk: true, ongkir: false, catatan: false, lain: false, ringkas: true },

            init() {
                ['namaPelanggan', 'baris', 'diskonBelanja', 'diskonBelanjaJenis', 'minDp',
                 'keepStok', 'tempo', 'tempoHari', 'metode', 'catatan', 'ongkir', 'ongkirSidik',
                 'diskonOngkir', 'diskonOngkirJenis', 'diskonBelanjaManual', 'diskonOngkirManual']
                    .forEach(k => this.$watch(k, () => this.simpanNanti()));

                // Promo dicek ulang tiap keranjang atau ongkir berubah — syarat
                // tingkatnya bergantung pada total belanja, jadi menambah satu
                // barang saja bisa mengubah potongan yang berlaku.
                ['baris', 'ongkir', 'metode'].forEach(k => this.$watch(k, () => this.muatPromoNanti()));

                if (this.baris.length) this.muatPromoNanti();
            },

            lipat(k) { this.buka[k] = ! this.buka[k]; },

            rupiah(n) { return 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID'); },

            /* ------------------------------------------------ kepala segmen */

            ringkasPelanggan() {
                @if($terpilih?->customer)
                    return @json($terpilih->customer->name ?? '');
                @else
                    return this.namaPelanggan || 'belum diisi';
                @endif
            },

            ringkasProduk() {
                if (!this.baris.length) return 'kosong';

                const pcs = this.baris.reduce((n, b) => n + (Number(b.qty) || 0), 0);

                return this.baris.length + ' jenis · ' + pcs + ' pcs';
            },

            ringkasOngkir() {
                if (this.metode === 'ambil_toko') return 'ambil di toko';
                if (!this.ongkir) return 'belum dihitung';

                return (this.ongkirBasi() ? 'perlu hitung ulang · ' : '') + this.rupiah(this.ongkirBersih());
            },

            ringkasLain() {
                const bagian = ['DP ' + (Number(this.minDp) || 0) + '%'];
                if (this.keepStok) bagian.push('keep stock');
                if (this.tempo) bagian.push('tempo' + (this.tempoHari ? ' ' + this.tempoHari + 'h' : ''));

                return bagian.join(' · ');
            },

            /* -------------------------------------------------------- produk */

            async cariProduk() {
                const q = this.cari.trim();

                if (q.length < 2) { this.hasil = []; return; }

                try {
                    const r = await fetch('/erp/api/products/search?sellable_only=1&q=' + encodeURIComponent(q),
                        { headers: { 'Accept': 'application/json' } });

                    this.hasil = r.ok ? await r.json() : [];
                } catch (e) { this.hasil = []; }
            },

            tambah(p) {
                this.masukkan({
                    id: p.id, nama: p.name, sku: p.sku, harga: Number(p.price) || 0, qty: 1,
                    berat: Number(p.weight_gram) || 0,
                });

                this.cari = '';
                this.hasil = [];
            },

            /*
             * Produk yang SUDAH ada tidak digandakan barisnya — jumlahnya yang
             * ditambah. Dua baris untuk produk yang sama membuat diskon per
             * baris jadi tidak jelas berlaku untuk yang mana.
             */
            masukkan(p) {
                const ada = this.baris.find(b => b.id === p.id);

                if (ada) { ada.qty = (Number(ada.qty) || 0) + (Number(p.qty) || 1); return; }

                this.baris.push({
                    key: ++this.nomor,
                    id: p.id, nama: p.nama, sku: p.sku ?? null,
                    harga: p.harga, qty: p.qty || 1,
                    berat: p.berat || 0,
                    diskonJenis: 'nominal', diskonNilai: 0, buka: false,
                    // Belum disentuh tangan → promo boleh mengisinya.
                    diskonManual: false, promo: null,
                });
            },

            /* ------------------------------------------------------- hitungan
             *
             * Rumusnya DISENGAJA sama persis dengan SalesOrderService: diskon
             * nominal itu potongan PER UNIT, dan tiap tahap dibulatkan ke rupiah.
             * Angka di layar cuma bayangan — yang tersimpan tetap hitungan
             * server — tapi bayangan yang meleset seribu rupiah membuat operator
             * berhenti percaya pada ringkasannya.
             */
            subtotalBaris(b) { return Math.round((Number(b.qty) || 0) * (Number(b.harga) || 0)); },

            diskonBaris(b) {
                const sub = this.subtotalBaris(b);
                const nilai = Number(b.diskonNilai) || 0;

                return b.diskonJenis === 'percent'
                    ? Math.round(sub * nilai / 100)
                    : Math.round(nilai * (Number(b.qty) || 0));
            },

            totalBaris(b) { return this.subtotalBaris(b) - this.diskonBaris(b); },

            subtotal()   { return this.baris.reduce((n, b) => n + this.subtotalBaris(b), 0); },
            diskonItem() { return this.baris.reduce((n, b) => n + this.diskonBaris(b), 0); },

            diskonTotal() {
                const dpp = this.subtotal() - this.diskonItem();
                const nilai = Number(this.diskonBelanja) || 0;

                return this.diskonBelanjaJenis === 'percent' ? Math.round(dpp * nilai / 100) : Math.round(nilai);
            },

            grand() {
                const dpp = this.subtotal() - this.diskonItem() - this.diskonTotal();

                return Math.round(dpp + this.ongkirBersih());
            },

            nilaiDp() { return Math.round(this.grand() * (Number(this.minDp) || 0) / 100); },

            /* --------------------------------------------------------- promo
             *
             * Promo MENGISI kotak diskon yang sudah ada, bukan jadi baris
             * tersendiri — sama seperti Kasir dan form SO. Dengan begitu SO yang
             * lahir dari chat tidak punya bentuk diskon yang berbeda dari SO
             * mana pun, dan laporan tidak perlu tahu asal potongannya.
             *
             * Angka yang diketik operator TIDAK PERNAH ditimpa: itu kesepakatan
             * dengan pembeli, dan mesin promo tidak berhak membatalkannya.
             * Tombol "Pakai promo" tetap disediakan supaya keputusannya bisa
             * dikembalikan tanpa menghitung sendiri.
             */
            muatPromoNanti() {
                clearTimeout(this.jamPromo);
                this.jamPromo = setTimeout(() => this.muatPromo(), 400);
            },

            async muatPromo() {
                if (!this.baris.length) {
                    this.promoOngkir = this.promoBelanja = null;
                    this.baris.forEach(b => { b.promo = null; });
                    return;
                }

                try {
                    const r = await fetch('{{ $terpilih ? route('crm.inbox.promo', $terpilih) : '' }}', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        },
                        body: JSON.stringify({
                            items: this.baris.map(b => ({
                                product_id: b.id, qty: Number(b.qty) || 1, unit_price: Number(b.harga) || 0,
                            })),
                            subtotal: this.subtotal() - this.diskonItem(),
                            // Ambil di toko → ongkir kotor 0, jadi promo ongkir
                            // tidak ikut menyala untuk sesuatu yang tak dikirim.
                            shipping_gross: this.metode === 'ambil_toko' || !this.ongkir ? 0 : this.ongkir.gross,
                        }),
                    });

                    if (!r.ok) return;

                    this.terapkanPromo(await r.json());
                } catch (e) { /* promo gagal dimuat: diskon manual tetap jalan */ }
            },

            terapkanPromo(d) {
                const perItem = d.item_discounts ?? {};

                for (const b of this.baris) {
                    const p = perItem[b.id];

                    b.promo = p ? p.promotion_name : null;

                    if (!p || b.diskonManual) continue;

                    b.diskonJenis = p.discount_type === 'percent' ? 'percent' : 'nominal';
                    b.diskonNilai = Number(p.discount_value) || 0;
                }

                this.promoBelanja = d.cart_total?.promotion_name ?? null;
                if (d.cart_total && !this.diskonBelanjaManual) {
                    this.diskonBelanja = Number(d.cart_total.discount_value) || 0;
                    this.diskonBelanjaJenis = d.cart_total.discount_type === 'percent' ? 'percent' : 'nominal';
                }

                this.promoOngkir = d.shipping?.promotion_name ?? null;
                if (d.shipping && !this.diskonOngkirManual) {
                    this.diskonOngkir = Number(d.shipping.discount_value) || 0;
                    this.diskonOngkirJenis = d.shipping.discount_type === 'percent' ? 'percent' : 'nominal';
                }
            },

            pakaiPromoBaris(b) { b.diskonManual = false; this.muatPromo(); },
            pakaiPromoBelanja() { this.diskonBelanjaManual = false; this.muatPromo(); },
            pakaiPromoOngkir() { this.diskonOngkirManual = false; this.muatPromo(); },

            /* -------------------------------------------------------- ongkir */

            /*
             * Ongkir dibayar = tarif − potongan, tidak pernah di bawah nol.
             * Tarif KOTOR-nya tetap dikirim ke SO: potongan ongkir dicatat
             * sebagai pengurang pendapatan tersendiri, bukan dengan
             * memura-murakan tarif kurirnya — yang dibayar ke kurir tetap penuh.
             */
            ongkirBersih() {
                // Ambil di toko = tidak ada ongkir, apa pun yang tersimpan.
                if (!this.ongkir || this.metode === 'ambil_toko') return 0;

                const kotor = Number(this.ongkir.gross) || 0;
                const nilai = Number(this.diskonOngkir) || 0;
                const potong = this.diskonOngkirJenis === 'percent' ? Math.round(kotor * nilai / 100) : nilai;

                return Math.max(0, kotor - potong);
            },

            // Sidik = produk + jumlahnya. Harga & diskon sengaja tidak ikut:
            // keduanya tidak mengubah berat, jadi menandai ongkir basi karena
            // diskon berubah cuma bikin peringatan yang tidak dipercaya.
            sidikKeranjang() {
                return JSON.stringify(this.baris.map(b => [b.id, Number(b.qty) || 0]).sort());
            },

            ongkirBasi() {
                // Tarif basi tidak jadi soal kalau memang tidak dipakai.
                if (this.metode === 'ambil_toko') return false;

                return !!this.ongkir && this.ongkirSidik !== this.sidikKeranjang();
            },

            /*
             * Membuang ongkir adalah jalan keluar yang SAH, bukan kelalaian.
             * Pesanan yang jadi diambil di toko, atau ongkirnya disepakati
             * belakangan, tetap harus bisa dibuat tanpa dipaksa menghitung
             * tarif yang tidak akan dipakai.
             */
            buangOngkir() {
                this.ongkir = null;
                this.ongkirSidik = '';
            },

            terimaDariOngkir(d) {
                this.ongkir = d.ongkir ?? d;

                // Produk penimbang ikut pindah ke keranjang: yang ditimbang di
                // tab Ongkir memang barang yang mau dipesan, dan mengetiknya
                // ulang di sini cuma kesempatan untuk salah.
                for (const p of (d.produk ?? [])) this.masukkan(p);

                this.ongkirSidik = this.sidikKeranjang();

                // Ongkir yang datang berarti barangnya dikirim, bukan diambil.
                this.metode = 'kurir';
                this.mode = 'buat';
                this.buka.ongkir = true;
            },

            // Keranjang dibawa serta supaya tab Ongkir tidak perlu diisi ulang
            // produknya; beratnya langsung terhitung dari sana.
            keOngkir() {
                window.dispatchEvent(new CustomEvent('hitung-ulang-ongkir', {
                    detail: { produk: this.baris.map(b => ({
                        id: b.id, nama: b.nama, harga: b.harga, qty: b.qty, berat: b.berat || 0,
                    })) },
                }));
                window.dispatchEvent(new CustomEvent('buka-tab', { detail: { tab: 'ongkir' } }));
            },

            /* --------------------------------------------------------- simpan */

            simpanNanti() {
                clearTimeout(this.jamSimpan);
                this.jamSimpan = setTimeout(() => this.simpan(), 800);
            },

            simpan() {
                fetch('{{ $terpilih ? route('crm.inbox.draft-pesanan', $terpilih) : '' }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        bagian: 'pesanan',
                        data: {
                            namaPelanggan: this.namaPelanggan,
                            baris: this.baris,
                            diskonBelanja: this.diskonBelanja,
                            diskonBelanjaJenis: this.diskonBelanjaJenis,
                            diskonBelanjaManual: this.diskonBelanjaManual,
                            diskonOngkir: this.diskonOngkir,
                            diskonOngkirJenis: this.diskonOngkirJenis,
                            diskonOngkirManual: this.diskonOngkirManual,
                            minDp: this.minDp, keepStok: this.keepStok,
                            tempo: this.tempo, tempoHari: this.tempoHari,
                            metode: this.metode, catatan: this.catatan,
                            ongkir: this.ongkir, ongkirSidik: this.ongkirSidik,
                        },
                    }),
                // Sengaja diam saat gagal: draft cuma kenyamanan, dan yang
                // mengikat tetap SO yang lahir lewat tombol Buat SO Draft.
                }).catch(() => {});
            },

            async muatPesanan() {
                try {
                    const r = await fetch('{{ $terpilih ? route('crm.inbox.pesanan', $terpilih) : '' }}',
                        { headers: { 'Accept': 'application/json' } });

                    if (r.ok) this.pesanan = (await r.json()).pesanan ?? [];
                } catch (e) { /* daftar lama tetap tampil */ }
            },

            /* ---------------------------------------------------------- buat */

            async buat() {
                if (this.sibuk) return;

                if (!this.baris.length) { this.galat = 'Tambahkan produk dulu.'; return; }

                /*
                 * Ongkir basi DITAHAN di sini, bukan sekadar ditandai.
                 *
                 * Tarif yang dihitung untuk keranjang lama akan tersimpan di SO
                 * sebagai angka yang sah — dipakai menagih pelanggan, lalu
                 * dipakai memesan resi. Selisihnya baru ketahuan saat kurir
                 * menimbang, dan saat itu nominalnya sudah terlanjur dijanjikan.
                 */
                if (this.ongkirBasi()) {
                    this.buka.ongkir = true;
                    this.galat = 'Ongkir dihitung untuk isi keranjang yang lama. Hitung ulang, atau buang ongkirnya.';
                    return;
                }

                this.sibuk = true;
                this.galat = '';

                const muatan = {
                    warehouse_id: {{ (int) $gudangTerpilih }},
                    @if($terpilih?->customer_id)
                        customer_id: {{ (int) $terpilih->customer_id }},
                    @else
                        customer_name: this.namaPelanggan.trim(),
                    @endif
                    items: this.baris.map(b => ({
                        product_id: b.id,
                        // Nama di nota; produknya tetap produk yang sama.
                        description: (b.nama || '').trim() || null,
                        qty: Number(b.qty) || 0,
                        unit_price: Number(b.harga) || 0,
                        discount_type: b.diskonJenis,
                        discount_value: Number(b.diskonNilai) || 0,
                    })),
                    global_discount_type: this.diskonBelanjaJenis,
                    global_discount_value: Number(this.diskonBelanja) || 0,
                    min_dp_percent: Number(this.minDp) || 0,
                    allow_backorder: this.keepStok,
                    is_tempo: this.tempo,
                    tempo_days: this.tempo && this.tempoHari !== '' ? Number(this.tempoHari) : null,
                    delivery_method: this.metode,
                    notes: this.catatan.trim() || null,
                };

                if (this.ongkir && this.metode !== 'ambil_toko') {
                    Object.assign(muatan, {
                        shipping_gross: this.ongkir.gross,
                        shipping_discount_type: this.diskonOngkirJenis,
                        shipping_discount_value: Number(this.diskonOngkir) || 0,
                        courier_name: this.ongkir.nama,
                        shipping_provider: this.ongkir.provider,
                        shipping_courier_code: this.ongkir.courier_code,
                        shipping_service_code: this.ongkir.service_code,
                    });
                }

                try {
                    const r = await fetch('{{ $terpilih ? route('crm.inbox.buat-so', $terpilih) : '' }}', {
                        method: 'POST',
                        body: JSON.stringify(muatan),
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        },
                    });
                    const d = await r.json().catch(() => ({}));

                    if (!r.ok || !d.success) {
                        const rinci = d.errors ? Object.values(d.errors).flat().join(' ') : '';
                        this.galat = d.error || rinci || d.message || 'Gagal membuat SO.';
                        return;
                    }

                    /*
                     * Penyusun dikosongkan SELURUHNYA — satu sesi menyusun = satu
                     * pesanan.
                     *
                     * Ongkir yang tertahan adalah celah paling halus di layar
                     * ini: ia dihitung untuk berat pesanan yang barusan jadi,
                     * dan begitu operator menyusun pesanan kedua dari chat yang
                     * sama, tarif lama itu ikut terpasang diam-diam. Ia terlihat
                     * sah — nama kurirnya benar, nominalnya benar untuk pesanan
                     * SEBELUMNYA — dan selisihnya baru ketahuan saat resi
                     * dipesan.
                     *
                     * Yang tidak ikut dibuang cuma alamat tujuan di tab Ongkir:
                     * alamat pelanggan memang tidak berubah antar pesanan.
                     */
                    this.baris = [];
                    this.diskonBelanja = '';
                    this.ongkir = null;
                    this.ongkirSidik = '';
                    this.diskonOngkir = '';
                    this.diskonOngkirJenis = 'nominal';
                    this.diskonOngkirManual = false;
                    this.diskonBelanjaJenis = 'nominal';
                    this.diskonBelanjaManual = false;
                    this.promoOngkir = this.promoBelanja = null;
                    this.catatan = '';
                    this.minDp = 50;
                    this.keepStok = false;
                    this.tempo = false;
                    this.tempoHari = '';
                    this.metode = 'kurir';

                    window.dispatchEvent(new CustomEvent('pesanan-dibuat'));

                    // Kembali ke daftar: SO yang baru lahir langsung terlihat di
                    // sana beserta statusnya.
                    await this.muatPesanan();
                    this.mode = 'daftar';
                } catch (e) {
                    this.galat = 'Jaringan bermasalah — SO belum tersimpan.';
                } finally {
                    this.sibuk = false;
                }
            },

            /*
             * Rincian lengkap + tautan bayar DITARUH di kotak ketik, bukan
             * langsung dikirim.
             *
             * Isinya harga, ongkir, dan tautan pembayaran — pesan yang paling
             * mahal salahnya di seluruh layar ini, dan sekali terkirim tidak
             * bisa ditarik. Satu tekan Enter setelah dibaca sekilas jauh lebih
             * murah daripada satu pesan salah kirim ke pelanggan.
             */
            async kirimRincian(p) {
                if (this.sibukRincian) return;

                this.sibukRincian = p.id;

                try {
                    const url = '{{ $terpilih ? url('/erp/crm/' . $terpilih->id . '/pesanan') : '' }}/'
                        + p.id + '/rincian';

                    const r = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        },
                    });
                    const d = await r.json().catch(() => ({}));

                    if (!r.ok || !d.teks) {
                        this.galat = d.error || d.message || 'Gagal menyiapkan rincian.';
                        return;
                    }

                    window.dispatchEvent(new CustomEvent('sisip-snippet', { detail: { teks: d.teks } }));
                } catch (e) {
                    this.galat = 'Jaringan bermasalah — rincian belum siap.';
                } finally {
                    this.sibukRincian = null;
                }
            },

            sisipKeChat(p) {
                const teks = 'Pesanan ' + p.nomor
                    + ' — total ' + this.rupiah(p.total)
                    + (p.status ? ' · ' + p.status : '')
                    + (p.catatan ? ' (' + p.catatan + ')' : '');

                window.dispatchEvent(new CustomEvent('sisip-snippet', { detail: { teks } }));
            },
        };
    }
</script>
