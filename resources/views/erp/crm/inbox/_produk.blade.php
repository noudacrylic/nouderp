{{-- Tab "Produk" pada rail kanan: menjawab pertanyaan yang paling sering
     datang di chat — "stoknya ada?", "harganya berapa?", "linknya mana?" —
     tanpa admin harus meninggalkan layar chat dan kehilangan konteksnya.

     Dipecah jadi TIGA sub-tab (9 Sep 2026) karena tiga jenis barang ini punya
     pekerjaan yang berbeda, dan satu daftar campur membuat dua di antaranya
     tenggelam:

       Web         — barang yang halamannya terbit di noudakrilik.com. Yang
                     dikirim: stok, harga, tautan, dan fotonya.
       Market Place— nama & alamat lapak yang diketik manual, TIDAK menempel ke
                     SKU. Yang dijual di lapak sering bukan satu SKU gudang.
       Custom      — barang buatan. Harganya baru lahir saat ditanya, jadi
                     harga & berat boleh ditetapkan dari sini lalu didorong ke
                     Jubelio tanpa pindah layar.

     Angka stoknya memakai rumus yang SAMA dengan yang dilihat pembeli di web
     (StokTersediaService), dan harganya harga SETELAH promo — admin tak boleh
     menjanjikan barang yang di web sudah habis, atau menyebut angka yang beda
     dengan yang terpampang di etalase. --}}
<div x-data="panelProduk(@js($terpilih?->id))" class="space-y-2"
     @foto-produk-selesai.window="mengirimFoto = null; galat = $event.detail.ok ? '' : $event.detail.error">

    {{-- ------------------------------------------------------------ sub-tab --}}
    <div class="flex gap-1 text-[11px]">
        <template x-for="t in subtab" :key="t.key">
            <button type="button" @click="pindah(t.key)"
                    :class="sub === t.key ? 'border-emerald-600 bg-emerald-50 text-emerald-700 font-medium'
                                          : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                    class="flex-1 px-2 py-1 rounded border" x-text="t.label"></button>
        </template>
    </div>

    <div class="flex items-center gap-2">
        <input type="text" x-model="kata" @input.debounce.400ms="muat()"
               :placeholder="sub === 'pasar' ? 'Cari nama lapak…' : 'Cari nama produk atau SKU…'"
               class="flex-1 border rounded px-2 py-1.5 text-sm">
        <button type="button" @click="muat()" title="Muat ulang"
                class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-50">↻</button>
    </div>

    {{-- Tanpa chat yang dibuka, separuh panel ini memang tidak bisa berbuat
         apa-apa: tak ada tujuan untuk mengirim, dan tak ada siapa untuk
         dititipi. Dulu tombolnya cuma disembunyikan — dan yang terjadi adalah
         orang mencari-cari tombol yang dikiranya hilang. Lebih baik dikatakan. --}}
    <p x-show="! percakapan" x-cloak class="text-[11px] text-gray-500 bg-gray-50 border border-gray-200 rounded px-2 py-1.5">
        Buka satu percakapan dulu untuk mengirim pesan, mengirim foto, atau menandai titipan stok.
        Tanpa itu panel ini hanya bisa dipakai membaca stok &amp; harga.
    </p>

    <p x-show="sibuk" class="text-xs text-gray-400 px-1">Mencari…</p>
    <p x-show="galat" x-cloak class="text-xs text-red-600 px-1" x-text="galat"></p>
    {{-- Kabar netral, bukan galat: "stoknya ternyata sudah ada" adalah hasil
         yang BENAR, dan mewarnainya merah membuat admin mengira ada yang salah. --}}
    <p x-show="kabar" x-cloak class="text-xs text-emerald-700 px-1" x-text="kabar"></p>

    {{-- ================================================================ WEB --}}
    {{-- Dikelompokkan per HALAMAN ETALASE, bukan per SKU.

         Satu halaman produk sering menampung beberapa SKU yang berbeda tipis
         ("1 Kotak", "1 Kotak Instant", "1 Kotak Packing Kayu"). Sebagai kartu
         terpisah mereka memakan seluruh layar dan tampak seperti tiga barang
         yang tak berhubungan — padahal yang ditanya pembeli justru
         PERBANDINGANNYA: mana yang lebih murah, mana yang stoknya ada.
         Sebagai baris di bawah satu nama, jawabannya terbaca sekilas. --}}
    <template x-if="sub === 'web'">
        <div class="space-y-2">
            <template x-for="g in grup" :key="g.id">
                <div class="border border-gray-200 rounded p-2 space-y-2">

                    <div class="flex gap-2">
                        {{-- Foto yang sama dengan gambar utama halaman produk:
                             admin melihat persis apa yang akan diterima pembeli
                             kalau tombol Foto ditekan. --}}
                        <img x-show="g.foto" :src="g.foto" alt="" loading="lazy"
                             class="shrink-0 w-14 h-14 object-cover rounded border border-gray-200 bg-gray-50">
                        <div class="min-w-0">
                            <div class="text-sm font-medium break-words" x-text="g.nama"></div>
                            <div class="text-[11px] text-gray-400 break-all" x-text="g.url"></div>
                        </div>
                    </div>

                    <table class="w-full text-[11px]">
                        <thead>
                            <tr class="text-[10px] text-gray-500 uppercase tracking-wide">
                                <th class="text-left font-medium py-1">Varian</th>
                                <th class="text-right font-medium py-1">Harga</th>
                                <th class="text-right font-medium py-1">Stok</th>
                                <th class="w-6"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="v in g.varian" :key="v.id">
                                <tr @click="g.pilih = v.id"
                                    :class="g.pilih === v.id ? 'bg-emerald-50' : 'hover:bg-gray-50'"
                                    class="cursor-pointer border-t border-gray-100 align-top">
                                    <td class="py-1 pr-1">
                                        <div class="font-medium break-words" x-text="v.label"></div>
                                        <div class="font-mono text-[10px] text-gray-400" x-text="v.sku"></div>
                                        <div x-show="v.promo" x-cloak class="text-[10px] text-amber-700" x-text="v.promo"></div>
                                        <div x-show="v.titipan" x-cloak class="text-[10px] text-amber-700">
                                            🔔 ditunggu <span x-text="angka(v.titipan_qty)"></span>
                                        </div>
                                    </td>
                                    <td class="py-1 text-right whitespace-nowrap">
                                        <div x-text="rp(v.harga)"></div>
                                        <div x-show="v.harga_coret" x-cloak class="text-[10px] text-gray-400 line-through"
                                             x-text="rp(v.harga_coret)"></div>
                                    </td>
                                    <td class="py-1 text-right whitespace-nowrap font-semibold"
                                        :class="v.stok > 0 ? 'text-emerald-700' : 'text-red-600'"
                                        x-text="angka(v.stok)"></td>
                                    {{-- Kolom kirim: satu klik langsung mengirim
                                         kalimat untuk BARIS ITU, tanpa memilih
                                         dulu — inilah yang paling sering dipakai. --}}
                                    <td class="py-1 pl-1 text-right">
                                        <button type="button" @click.stop="g.pilih = v.id; kirim(kalimat(v, g))"
                                                title="Kirim harga, stok &amp; link varian ini"
                                                class="w-5 h-5 flex items-center justify-center rounded bg-emerald-600 hover:bg-emerald-700 text-white text-[10px]">➤</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    {{-- Aksi selebihnya menempel pada baris yang SEDANG DIPILIH.
                         Menaruhnya di tiap baris membuat tabel selebar rail ini
                         tak terbaca lagi; menaruhnya di tingkat kelompok membuat
                         "foto varian mana?" jadi pertanyaan tanpa jawaban. --}}
                    <template x-if="varianTerpilih(g)">
                        <div class="space-y-1 border-t border-gray-200 pt-2">
                            <div class="text-[10px] text-gray-500">
                                Varian terpilih: <span class="font-medium" x-text="varianTerpilih(g).label"></span>
                            </div>

                            <div class="flex items-center gap-1">
                                <button type="button" @click="kirim(kalimat(varianTerpilih(g), g))"
                                        class="flex-1 min-w-0 bg-emerald-600 hover:bg-emerald-700 text-white rounded px-2 py-1.5 text-xs">
                                    Kirim info &amp; link
                                </button>

                                {{-- Pratinjau tautan di WhatsApp tidak bisa diandalkan: kalau
                                     pengambil halaman Meta gagal membuka etalase, yang sampai
                                     ke pembeli cuma sebaris URL. Tombol ini mengirim fotonya
                                     sendiri berikut caption yang sama. --}}
                                <button type="button" x-show="g.foto && percakapan" x-cloak
                                        @click="kirimFoto(varianTerpilih(g), g)"
                                        :disabled="mengirimFoto === g.pilih"
                                        title="Kirim beserta foto produk"
                                        class="shrink-0 flex items-center gap-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1.5 text-xs disabled:opacity-50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M3 16.5 8.25 11l4.5 4.5L16.5 12l4.5 4.5M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Z"/>
                                    </svg>
                                    <span x-text="mengirimFoto === g.pilih ? '…' : 'Foto'"></span>
                                </button>

                                <button type="button" @click="sisip(kalimat(varianTerpilih(g), g))"
                                        title="Sisipkan tanpa mengirim"
                                        class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs hover:bg-gray-50">＋</button>
                            </div>

                            {{-- Titipan "kabari kalau stok ada".
                                 SELALU ditawarkan selama ada chat yang dibuka, tidak
                                 cuma saat stoknya nol. Disembunyikan pada barang yang
                                 ready memang lebih rapi, tapi akibatnya orang mencari
                                 tombol yang dikiranya hilang — dan menandai barang yang
                                 stoknya ternyata sudah ada tetap berguna: kabarnya
                                 langsung berangkat, dan itu justru yang diinginkan. --}}
                            <div x-show="percakapan" x-cloak class="text-[11px]">
                                <template x-if="varianTerpilih(g).titipan">
                                    <span class="text-amber-700">
                                        🔔 Ditandai — dikabari saat stok siap mencapai
                                        <b x-text="angka(varianTerpilih(g).titipan_qty)"></b>.
                                        <button type="button" @click="lepasTitipan(varianTerpilih(g))"
                                                class="underline hover:text-amber-900">Batalkan</button>
                                    </span>
                                </template>

                                {{-- Jumlahnya WAJIB bisa diisi, bukan selalu 1.
                                     Kasus yang sebenarnya bukan "stok habis" melainkan
                                     "stok tinggal 5, yang dibutuhkan 10": dikabari saat
                                     satu pcs masuk cuma membuat pelanggan datang lalu
                                     pulang dengan tangan kosong. --}}
                                <template x-if="! varianTerpilih(g).titipan && titipUntuk === g.pilih">
                                    <div class="border border-dashed border-gray-300 rounded p-2 space-y-1 mt-1">
                                        <label class="block text-[10px] text-gray-500 uppercase tracking-wide">
                                            Kabari saat stok siap mencapai
                                        </label>
                                        <input type="number" step="1" min="1" x-model="titipQty"
                                               class="border rounded w-full px-2 py-1 text-xs">
                                        <p x-show="Number(titipQty) <= varianTerpilih(g).stok" x-cloak
                                           class="text-[10px] text-amber-700 leading-snug">
                                            Stok siap sekarang <span x-text="angka(varianTerpilih(g).stok)"></span> —
                                            kabarnya akan langsung berangkat.
                                        </p>
                                        <div class="flex gap-1">
                                            <button type="button" @click="titip(varianTerpilih(g))" :disabled="menyimpan"
                                                    class="flex-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">
                                                Tandai
                                            </button>
                                            <button type="button" @click="titipUntuk = null"
                                                    class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="! varianTerpilih(g).titipan && titipUntuk !== g.pilih">
                                    <button type="button" @click="bukaTitipan(varianTerpilih(g))"
                                            class="text-emerald-700 hover:underline">
                                        + Kabari pelanggan kalau stok sudah ada
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </template>

    {{-- ======================================================= MARKET PLACE --}}
    <template x-if="sub === 'pasar'">
        <div class="space-y-2">
            <template x-for="t in pasar" :key="t.id">
                <div class="border border-gray-200 rounded p-2 space-y-1">
                    <template x-if="ubahPasar !== t.id">
                        <div class="space-y-1">
                            <div class="text-sm font-medium break-words" x-text="t.nama"></div>
                            <div class="text-[11px] text-gray-400 break-all" x-text="t.url"></div>
                            <div class="flex items-center gap-1 pt-1">
                                <button type="button" @click="kirim(kalimatPasar(t))"
                                        class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded px-2 py-1.5 text-xs">
                                    Kirim link
                                </button>
                                <button type="button" @click="sisip(kalimatPasar(t))" title="Sisipkan tanpa mengirim"
                                        class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs hover:bg-gray-50">＋</button>
                                <button type="button" @click="ubahPasar = t.id; namaBaru = t.nama; urlBaru = t.url"
                                        class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs hover:bg-gray-50">Edit</button>
                            </div>
                        </div>
                    </template>

                    <template x-if="ubahPasar === t.id">
                        <div class="space-y-1">
                            <input type="text" x-model="namaBaru" maxlength="160" placeholder="Nama produk di lapak"
                                   class="border rounded w-full px-2 py-1 text-xs">
                            <input type="url" x-model="urlBaru" maxlength="500" placeholder="https://shopee.co.id/…"
                                   class="border rounded w-full px-2 py-1 text-xs">
                            <div class="flex gap-1">
                                <button type="button" @click="simpanPasar(t)" :disabled="menyimpan"
                                        class="flex-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">Simpan</button>
                                <button type="button" @click="ubahPasar = null"
                                        class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                                <button type="button" @click="hapusPasar(t)" title="Hapus"
                                        class="border border-gray-300 rounded px-2 py-1 text-xs text-gray-400 hover:bg-red-50 hover:text-red-600">&times;</button>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Tombol tambah berdiri di BAWAH daftar, bukan di atas: yang paling
                 sering dilakukan adalah mengirim tautan yang sudah ada, bukan
                 membuat yang baru. --}}
            <div x-show="ubahPasar === 'baru'" x-cloak class="border border-dashed border-gray-300 rounded p-2 space-y-1">
                <input type="text" x-model="namaBaru" maxlength="160" placeholder="Nama produk di lapak"
                       class="border rounded w-full px-2 py-1 text-xs">
                <input type="url" x-model="urlBaru" maxlength="500" placeholder="https://shopee.co.id/…"
                       class="border rounded w-full px-2 py-1 text-xs">
                <div class="flex gap-1">
                    <button type="button" @click="simpanPasar(null)" :disabled="menyimpan"
                            class="flex-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">Simpan</button>
                    <button type="button" @click="ubahPasar = null"
                            class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                </div>
            </div>

            <button type="button" x-show="ubahPasar !== 'baru'"
                    @click="ubahPasar = 'baru'; namaBaru = ''; urlBaru = ''"
                    class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs text-emerald-700 hover:bg-emerald-50">
                + Tambah link
            </button>
        </div>
    </template>

    {{-- ============================================================= CUSTOM --}}
    <template x-if="sub === 'custom'">
        <div class="space-y-2">
            <template x-for="p in hasil" :key="p.id">
                <div class="border border-gray-200 rounded p-2 space-y-2">

                    <div class="min-w-0">
                        <div class="text-sm font-medium break-words" x-text="p.nama"></div>
                        <div class="text-[11px] text-gray-500">
                            <span class="font-mono" x-text="p.sku"></span>
                            <span class="ml-1 px-1 rounded bg-violet-100 text-violet-700">custom</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-center">
                        <div class="rounded bg-gray-50 py-1">
                            <div class="text-[10px] text-gray-500 uppercase tracking-wide">Harga</div>
                            <div class="text-sm font-semibold" x-text="rp(p.harga)"></div>
                        </div>
                        <div class="rounded bg-gray-50 py-1">
                            <div class="text-[10px] text-gray-500 uppercase tracking-wide">Berat</div>
                            <div class="text-sm font-semibold" x-text="p.berat ? angka(p.berat) + ' g' : '—'"></div>
                        </div>
                    </div>

                    {{-- Harga & berat barang buatan lahir di tengah percakapan.
                         Kalau harus dicatat di menu Produk, di lapangan ia tidak
                         pernah dicatat sama sekali — cuma diketik di WhatsApp
                         lalu hilang. --}}
                    <template x-if="ubahHarga === p.id">
                        <div class="border border-dashed border-gray-300 rounded p-2 space-y-1">
                            <label class="block text-[10px] text-gray-500 uppercase tracking-wide">Harga</label>
                            <input type="number" step="1" min="0" x-model="hargaBaru"
                                   class="border rounded w-full px-2 py-1 text-xs">
                            <label class="block text-[10px] text-gray-500 uppercase tracking-wide">Berat (gram)</label>
                            <input type="number" step="1" min="0" x-model="beratBaru"
                                   class="border rounded w-full px-2 py-1 text-xs">
                            <div class="flex gap-1 pt-1">
                                <button type="button" @click="simpanHarga(p, false)" :disabled="menyimpan"
                                        class="flex-1 border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50 disabled:opacity-50">Simpan</button>
                                <button type="button" @click="ubahHarga = null"
                                        class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                            </div>
                            <button type="button" @click="simpanHarga(p, true)" :disabled="menyimpan"
                                    class="w-full border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">
                                Simpan &amp; kirim ke Jubelio
                            </button>
                            <p x-show="pesanJubelio" x-cloak class="text-[11px] text-gray-500" x-text="pesanJubelio"></p>

                            {{-- Stok berdiri di petaknya sendiri, dengan tombolnya
                                 sendiri: ia TIDAK menyentuh stok ERP, dan menaruhnya
                                 di bawah tombol "Simpan" yang sama membuat orang
                                 mengira persediaan ERP ikut berubah. Angka ini
                                 artinya "berapa yang sanggup dikerjakan", bukan
                                 "berapa yang ada di rak" — barang custom memang
                                 belum ada wujudnya sampai dipesan. --}}
                            <div class="border-t border-gray-200 pt-2 mt-2 space-y-1">
                                <label class="block text-[10px] text-gray-500 uppercase tracking-wide">Stok di Jubelio</label>
                                <input type="number" step="1" min="0" x-model="stokBaru"
                                       class="border rounded w-full px-2 py-1 text-xs">
                                <p class="text-[10px] text-gray-400 leading-snug">
                                    Hanya mengubah stok di Jubelio. Stok ERP tidak tersentuh.
                                </p>
                                <button type="button" @click="kirimStok(p)" :disabled="menyimpan"
                                        class="w-full border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">
                                    Kirim stok ke Jubelio
                                </button>
                                <p x-show="pesanStok" x-cloak class="text-[11px] leading-snug"
                                   :class="stokGagal ? 'text-red-600' : 'text-gray-500'" x-text="pesanStok"></p>
                            </div>
                        </div>
                    </template>

                    <button type="button" x-show="ubahHarga !== p.id"
                            @click="ubahHarga = p.id; hargaBaru = p.harga; beratBaru = p.berat; stokBaru = 0; pesanJubelio = ''; pesanStok = ''"
                            class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs hover:bg-gray-50">
                        Edit Harga
                    </button>

                    {{-- Titipan "kabari kalau stok ada".
                         SELALU ditawarkan selama ada chat yang dibuka, tidak
                         cuma saat stoknya nol. Disembunyikan pada barang yang
                         ready memang lebih rapi, tapi akibatnya orang mencari
                         tombol yang dikiranya hilang — dan menandai barang yang
                         stoknya ternyata sudah ada tetap berguna: kabarnya
                         langsung berangkat, dan itu justru yang diinginkan. --}}
                    <div x-show="percakapan" x-cloak class="text-[11px] px-1">
                        <template x-if="p.titipan">
                            <span class="text-amber-700">
                                🔔 Ditandai — dikabari saat stok siap mencapai <b x-text="angka(p.titipan_qty)"></b>.
                                <button type="button" @click="lepasTitipan(p)" class="underline hover:text-amber-900">Batalkan</button>
                            </span>
                        </template>

                        {{-- Jumlahnya WAJIB bisa diisi, bukan selalu 1.
                             Kasus yang sebenarnya bukan "stok habis" melainkan
                             "stok tinggal 5, yang dibutuhkan 10": dikabari saat
                             satu pcs masuk cuma membuat pelanggan datang lalu
                             pulang dengan tangan kosong. --}}
                        <template x-if="! p.titipan && titipUntuk === p.id">
                            <div class="border border-dashed border-gray-300 rounded p-2 space-y-1 mt-1">
                                <label class="block text-[10px] text-gray-500 uppercase tracking-wide">
                                    Kabari saat stok siap mencapai
                                </label>
                                <input type="number" step="1" min="1" x-model="titipQty"
                                       class="border rounded w-full px-2 py-1 text-xs">
                                <p x-show="Number(titipQty) <= p.stok" x-cloak class="text-[10px] text-amber-700 leading-snug">
                                    Stok siap sekarang <span x-text="angka(p.stok)"></span> — kabarnya akan langsung berangkat.
                                </p>
                                <div class="flex gap-1">
                                    <button type="button" @click="titip(p)" :disabled="menyimpan"
                                            class="flex-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">
                                        Tandai
                                    </button>
                                    <button type="button" @click="titipUntuk = null"
                                            class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                                </div>
                            </div>
                        </template>

                        <template x-if="! p.titipan && titipUntuk !== p.id">
                            <button type="button" @click="bukaTitipan(p)"
                                    class="text-emerald-700 hover:underline">
                                + Kabari pelanggan kalau stok sudah ada
                            </button>
                        </template>
                    </div>

                    {{-- --------------------------------------------- tautan --}}
                    <div class="space-y-1">
                        <template x-for="t in p.tautan" :key="t.id">
                            <div class="space-y-1">
                                <template x-if="ubahTautan !== t.id">
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="kirim(kalimatTautan(p, t))"
                                                class="flex-1 min-w-0 text-left border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1.5 text-xs truncate"
                                                :title="t.url">
                                            Kirim <span x-text="t.judul"></span>
                                        </button>
                                        <button type="button" @click="sisip(kalimatTautan(p, t))" title="Sisipkan tanpa mengirim"
                                                class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs hover:bg-gray-50">＋</button>
                                        <button type="button" @click="ubahTautan = t.id; judulBaru = t.judul; urlBaru = t.url"
                                                class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs hover:bg-gray-50">Edit</button>
                                    </div>
                                </template>

                                <template x-if="ubahTautan === t.id">
                                    <div class="border border-dashed border-gray-300 rounded p-2 space-y-1">
                                        <input type="text" x-model="judulBaru" maxlength="120" placeholder="Judul, mis. Shopee"
                                               class="border rounded w-full px-2 py-1 text-xs">
                                        <input type="url" x-model="urlBaru" maxlength="500" placeholder="https://shopee.co.id/…"
                                               class="border rounded w-full px-2 py-1 text-xs">
                                        <div class="flex gap-1">
                                            <button type="button" @click="simpanTautan(p, t)" :disabled="menyimpan"
                                                    class="flex-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">Simpan</button>
                                            <button type="button" @click="ubahTautan = null"
                                                    class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                                            <button type="button" @click="hapusTautan(p, t)" title="Hapus tautan"
                                                    class="border border-gray-300 rounded px-2 py-1 text-xs text-gray-400 hover:bg-red-50 hover:text-red-600">&times;</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div x-show="ubahTautan === 'baru:' + p.id" x-cloak
                             class="border border-dashed border-gray-300 rounded p-2 space-y-1">
                            <input type="text" x-model="judulBaru" maxlength="120" placeholder="Judul, mis. Shopee CS2"
                                   class="border rounded w-full px-2 py-1 text-xs">
                            <input type="url" x-model="urlBaru" maxlength="500" placeholder="https://shopee.co.id/…"
                                   class="border rounded w-full px-2 py-1 text-xs">
                            <div class="flex gap-1">
                                <button type="button" @click="simpanTautan(p, null)" :disabled="menyimpan"
                                        class="flex-1 border border-emerald-600 text-emerald-700 hover:bg-emerald-50 rounded px-2 py-1 text-xs disabled:opacity-50">Simpan</button>
                                <button type="button" @click="ubahTautan = null"
                                        class="border border-gray-300 rounded px-2 py-1 text-xs hover:bg-gray-50">Batal</button>
                            </div>
                        </div>

                        <button type="button" x-show="ubahTautan !== 'baru:' + p.id"
                                @click="ubahTautan = 'baru:' + p.id; judulBaru = ''; urlBaru = ''"
                                class="w-full text-left text-[11px] text-emerald-700 hover:underline px-1">
                            + Tambah tautan (Shopee, Tokopedia, …)
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </template>

    <p x-show="!sibuk && kosong()" class="text-sm text-gray-500 px-1" x-text="pesanKosong()"></p>
</div>

<script>
function panelProduk(percakapanId) {
    return {
        subtab: [
            { key: 'web',    label: 'Web' },
            { key: 'pasar',  label: 'Market Place' },
            { key: 'custom', label: 'Custom' },
        ],
        sub: 'web',
        percakapan: percakapanId,

        kata: '', hasil: [], grup: [], pasar: [], sibuk: false, galat: '', kabar: '',
        menyimpan: false, mengirimFoto: null,

        // Satu penanda "sedang diedit" per jenis baris; nilainya id baris, atau
        // penanda 'baru' untuk formulir tambah.
        ubahPasar: null, ubahTautan: null, ubahHarga: null,
        // Titipan stok: id produk yang formulirnya terbuka + jumlah yang ditunggu.
        titipUntuk: null, titipQty: 1,
        namaBaru: '', judulBaru: '', urlBaru: '',
        hargaBaru: 0, beratBaru: 0, pesanJubelio: '',
        // Stok Jubelio berdiri terpisah dari harga: jalur simpannya lain, dan
        // hasilnya harus terbaca sendiri supaya tak tertukar dengan pesan harga.
        stokBaru: 0, pesanStok: '', stokGagal: false,

        pindah(key) {
            if (this.sub === key) return;
            this.sub = key;
            this.kata = '';
            this.ubahPasar = this.ubahTautan = this.ubahHarga = null;
            this.muat();
        },

        async muat() {
            this.sibuk = true;
            this.galat = '';
            try {
                if (this.sub === 'pasar') {
                    const r = await fetch('{{ route('crm.pasar.index') }}?q=' + encodeURIComponent(this.kata),
                                          { headers: { 'Accept': 'application/json' } });
                    this.pasar = (await r.json()).tautan || [];
                } else {
                    const r = await fetch('{{ route('crm.produk.cari') }}?mode=' + this.sub
                                          + '&chat=' + (this.percakapan ?? '')
                                          + '&q=' + encodeURIComponent(this.kata),
                                          { headers: { 'Accept': 'application/json' } });
                    const j = await r.json();

                    if (this.sub === 'web') {
                        // Varian pertama dipilih di muka: tanpa itu seluruh baris
                        // aksi di bawah tabel kosong sampai ada yang menebak bahwa
                        // barisnya harus diklik dulu.
                        this.grup = (j.grup || []).map(g => ({ ...g, pilih: g.varian[0]?.id ?? null }));
                        this.hasil = [];
                    } else {
                        this.hasil = j.produk || [];
                        this.grup = [];
                    }
                }
            } catch (e) {
                this.hasil = [];
                this.grup = [];
                this.pasar = [];
                this.galat = 'Daftar gagal dimuat. Coba muat ulang.';
            } finally {
                this.sibuk = false;
            }
        },

        kosong() {
            if (this.sub === 'pasar') return this.pasar.length === 0;
            if (this.sub === 'web')   return this.grup.length === 0;
            return this.hasil.length === 0;
        },

        /** Baris yang sedang dipilih di sebuah kelompok. */
        varianTerpilih(g) {
            return g.varian.find(v => v.id === g.pilih) ?? null;
        },

        pesanKosong() {
            if (this.sub === 'pasar') {
                return this.kata ? 'Tidak ada lapak yang cocok.' : 'Belum ada link marketplace. Tambahkan di bawah.';
            }
            if (this.sub === 'custom') {
                return this.kata ? 'Tidak ada produk custom yang cocok.' : 'Ketik nama atau SKU produk custom.';
            }
            return this.kata ? 'Tidak ada produk terbit yang cocok.' : 'Ketik nama atau SKU untuk mencari.';
        },

        rp(n) {
            return 'Rp' + Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
        },

        // Stok disimpan sebagai desimal (satuan bisa meter/kg), tapi bilangan
        // bulat tak perlu dipamerkan berkoma nol.
        angka(n) {
            const v = Number(n || 0);
            return Number.isInteger(v) ? String(v) : v.toLocaleString('id-ID', { maximumFractionDigits: 2 });
        },

        /* ------------------------------------------------------- kalimat jadi */

        /*
         * `grup` opsional: di sub-tab Web tautannya milik HALAMAN etalase, bukan
         * SKU — satu halaman menampung banyak varian. Sub-tab lain memanggil
         * tanpa kelompok, dan kalimatnya memang berhenti sebelum tautan.
         */
        kalimat(p, grup = null) {
            // Satuannya sengaja tidak disebut: SKU gudang bisa berbasis
            // meter atau kilogram, dan menuliskan "pcs" untuk semuanya
            // menghasilkan kalimat yang salah di sebagian barang.
            const stok = p.stok > 0
                ? this.angka(p.stok)
                : (p.preorder ? 'kosong, tapi bisa preorder' : 'sedang kosong');

            const harga = p.harga_coret
                ? this.rp(p.harga) + ' (diskon dari ' + this.rp(p.harga_coret) + ')'
                : this.rp(p.harga);

            let teks = 'Stok untuk ' + p.nama + ' adalah ' + stok + '.'
                     + '\nUntuk harganya ' + harga + '.';

            const url = grup?.url ?? p.url;

            if (url) {
                teks += '\nUntuk informasi lebih lanjut bisa lihat di link berikut:\n' + url;
            }

            return teks;
        },

        kalimatPasar(t) {
            return 'Berikut link untuk produk ' + t.nama + ':\n' + t.url;
        },

        kalimatTautan(p, t) {
            return 'Berikut link ' + t.judul + ' untuk produk ' + p.nama + ':\n' + t.url;
        },

        /*
         * Kotak ketik dan panel ini tidak saling kenal: keduanya bicara lewat
         * peristiwa jendela yang sudah dipakai tab Template. Dengan begitu
         * penjaga jendela 24 jam, mode aman, dan gelembung tanpa muat ulang
         * semuanya tetap berlaku tanpa satu pun digandakan di sini.
         */
        sisip(teks) {
            window.dispatchEvent(new CustomEvent('sisip-teks', { detail: { teks } }));
        },

        kirim(teks) {
            window.dispatchEvent(new CustomEvent('sisip-teks', { detail: { teks, kirim: true } }));
        },

        /* ------------------------------------------------------------- foto */

        /*
         * Foto TIDAK lewat peristiwa 'sisip-teks': kotak ketik hanya tahu
         * teks, dan menempelkan gambar ke sana berarti menyalin ulang seluruh
         * jalur unggah. Yang dikirim di sini adalah ALAMAT foto etalase; server
         * yang menariknya, menyimpannya sebagai lampiran keluar, lalu
         * mengirimnya — persis seperti gambar yang ditempel admin.
         */
        kirimFoto(p, grup = null) {
            const foto = grup?.foto ?? p.foto;

            if (! this.percakapan || ! foto) return;
            this.mengirimFoto = p.id;
            this.galat = '';

            window.dispatchEvent(new CustomEvent('kirim-foto-produk', {
                detail: { foto, caption: this.kalimat(p, grup) },
            }));
        },

        /* ------------------------------------------------- tautan marketplace */

        async simpanPasar(t) {
            if (! this.namaBaru.trim() || ! this.urlBaru.trim()) return;
            this.menyimpan = true;
            this.galat = '';
            try {
                const url = t
                    ? '{{ url('erp/crm/pasar') }}/' + t.id
                    : '{{ route('crm.pasar.store') }}';

                const r = await fetch(url, {
                    method: 'POST',
                    headers: this.kepala(),
                    body: JSON.stringify({ nama: this.namaBaru.trim(), url: this.urlBaru.trim() }),
                });
                if (! r.ok) throw new Error();
                const baris = (await r.json()).tautan;

                if (t) {
                    Object.assign(t, baris);
                } else {
                    this.pasar.push(baris);
                }
                this.ubahPasar = null;
            } catch (e) {
                this.galat = 'Link gagal disimpan. Periksa alamatnya — harus lengkap dengan https://';
            } finally {
                this.menyimpan = false;
            }
        },

        async hapusPasar(t) {
            if (! confirm('Hapus link "' + t.nama + '"?')) return;
            try {
                await fetch('{{ url('erp/crm/pasar') }}/' + t.id, {
                    method: 'DELETE',
                    headers: this.kepala(),
                });
                this.pasar = this.pasar.filter(x => x.id !== t.id);
                this.ubahPasar = null;
            } catch (e) {
                this.galat = 'Link gagal dihapus.';
            }
        },

        /* ---------------------------------------------------- tautan per SKU */

        async simpanTautan(p, t) {
            if (! this.judulBaru.trim() || ! this.urlBaru.trim()) return;
            this.menyimpan = true;
            this.galat = '';
            try {
                const url = t
                    ? '{{ url('erp/crm/produk/tautan') }}/' + t.id
                    : '{{ url('erp/crm/produk') }}/' + p.id + '/tautan';

                const r = await fetch(url, {
                    method: 'POST',
                    headers: this.kepala(),
                    body: JSON.stringify({ judul: this.judulBaru.trim(), url: this.urlBaru.trim() }),
                });
                if (! r.ok) throw new Error();
                const baris = (await r.json()).tautan;

                if (t) {
                    Object.assign(t, baris);
                } else {
                    p.tautan.push(baris);
                }
                this.ubahTautan = null;
            } catch (e) {
                this.galat = 'Tautan gagal disimpan. Periksa alamatnya — harus lengkap dengan https://';
            } finally {
                this.menyimpan = false;
            }
        },

        async hapusTautan(p, t) {
            if (! confirm('Hapus tautan "' + t.judul + '"?')) return;
            try {
                await fetch('{{ url('erp/crm/produk/tautan') }}/' + t.id, {
                    method: 'DELETE',
                    headers: this.kepala(),
                });
                p.tautan = p.tautan.filter(x => x.id !== t.id);
                this.ubahTautan = null;
            } catch (e) {
                this.galat = 'Tautan gagal dihapus.';
            }
        },

        /* --------------------------------------------------- harga & berat */

        async simpanHarga(p, keJubelio) {
            this.menyimpan = true;
            this.pesanJubelio = '';
            this.galat = '';
            try {
                const r = await fetch('{{ url('erp/crm/produk') }}/' + p.id + '/harga', {
                    method: 'POST',
                    headers: this.kepala(),
                    body: JSON.stringify({
                        harga: Number(this.hargaBaru || 0),
                        berat: Number(this.beratBaru || 0),
                        kirim_jubelio: keJubelio,
                    }),
                });
                const j = await r.json();
                if (! r.ok) throw new Error(j.message || '');

                p.harga = j.harga;
                p.berat = j.berat;
                this.pesanJubelio = j.pesan;

                // Ditutup hanya kalau Jubelio tidak ikut: hasil pengirimannya
                // wajib sempat terbaca, bukan lenyap bersama kotaknya.
                if (! keJubelio) this.ubahHarga = null;
            } catch (e) {
                this.galat = 'Harga gagal disimpan.';
            } finally {
                this.menyimpan = false;
            }
        },

        /*
         * Stok Jubelio TIDAK lewat simpanHarga(): endpointnya lain dan
         * akibatnya lain. Harga & berat menulis ke basis data ERP; angka ini
         * hanya berjalan ke Jubelio. Menggabungkannya jadi satu tombol
         * membuat satu kegagalan menyeret yang lain, dan lebih buruk: membuat
         * orang mengira stok ERP ikut berubah.
         */
        async kirimStok(p) {
            this.menyimpan = true;
            this.pesanStok = '';
            this.stokGagal = false;
            try {
                const r = await fetch('{{ url('erp/crm/produk') }}/' + p.id + '/stok-jubelio', {
                    method: 'POST',
                    headers: this.kepala(),
                    body: JSON.stringify({ stok: Number(this.stokBaru || 0) }),
                });
                const j = await r.json();

                this.pesanStok = j.pesan || (r.ok ? 'Stok terkirim.' : 'Stok gagal dikirim.');
                this.stokGagal = ! r.ok || ! j.ok;
            } catch (e) {
                this.pesanStok = 'Jaringan bermasalah — stok belum dikirim.';
                this.stokGagal = true;
            } finally {
                this.menyimpan = false;
            }
        },

        /*
         * Titipan stok. Jawabannya bisa berkata "stoknya ternyata sudah ada" —
         * kabar langsung diantrekan dan tandanya TIDAK dipasang. Itu bukan
         * kegagalan, jadi pesannya ditampilkan apa adanya alih-alih dianggap
         * galat.
         */
        /*
         * Bawaannya jumlah yang MASIH KURANG, bukan 1: yang menandai barang
         * berstok 38 hampir pasti sedang menunggu lebih dari itu, dan bawaan 1
         * membuat kabarnya berangkat seketika — persis kebingungan yang
         * dilaporkan. Barang yang memang habis tetap mulai dari 1.
         */
        bukaTitipan(p) {
            this.titipUntuk = p.id;
            this.titipQty = p.stok > 0 ? Math.floor(p.stok) + 1 : 1;
        },

        async titip(p) {
            if (! this.percakapan) return;
            const qty = Math.max(1, Number(this.titipQty) || 1);

            this.menyimpan = true;
            this.galat = '';
            this.kabar = '';
            try {
                const r = await fetch('{{ url('erp/crm') }}/' + this.percakapan + '/titipan-stok', {
                    method: 'POST',
                    headers: this.kepala(),
                    body: JSON.stringify({ product_id: p.id, qty }),
                });
                const j = await r.json();
                if (! r.ok) throw new Error(j.message || '');

                p.titipan = j.ditandai ? j.watch_id : null;
                p.titipan_qty = qty;
                this.kabar = j.pesan;
                this.titipUntuk = null;
            } catch (e) {
                this.galat = 'Titipan gagal disimpan.';
            } finally {
                this.menyimpan = false;
            }
        },

        async lepasTitipan(p) {
            try {
                await fetch('{{ url('erp/crm/titipan-stok') }}/' + p.titipan, {
                    method: 'DELETE',
                    headers: this.kepala(),
                });
                p.titipan = null;
                this.kabar = '';
            } catch (e) {
                this.galat = 'Titipan gagal dibatalkan.';
            }
        },

        kepala() {
            return {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
            };
        },
    };
}
</script>
