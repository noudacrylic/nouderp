{{-- Panel cek ongkir di rail CRM.

     Versi ringkas dari halaman Cek Ongkir: isi tujuan, isi berat, dapat daftar
     kurir. Ada di sini karena ongkir hampir selalu ditanyakan DI TENGAH chat —
     pindah tab, hitung, lalu kembali dan kehilangan konteks adalah alasan
     pertanyaan itu dijawab dengan tebakan.

     Dua hal yang membuatnya tetap lengkap meski sempit:

     - Berat dihitung dari PRODUK. Menuntut admin menebak gram saat pelanggan
       cuma menyebut "meja akrilik 2" adalah sumber ongkir yang meleset; berat
       & dimensi sudah tersimpan di master produk, jadi biar mesin yang
       menjumlahkan. Tetap bisa ditimpa manual untuk paket tak biasa.

     - Titik lokasi opsional. Diisi, kurir instant ikut dihitung; dikosongkan,
       hanya reguler. Tanpa ini "bisa dikirim sekarang?" tidak terjawab dari
       layar chat.

     Perhitungannya menumpang endpoint Cek Ongkir yang sudah ada, bukan jalur
     sendiri: validasi tujuan, penjaga gudang tanpa alamat, dan aturan instant
     tidak boleh punya dua versi yang bisa berbeda diam-diam. --}}
@php
    $kaOn = \App\Models\ShippingSetting::for('kiriminaja')->is_enabled;
    $btOn = \App\Models\ShippingSetting::for('biteship')->is_enabled;
    $jsOn = \App\Models\ShippingSetting::for('jubelio_shipment')->is_enabled;

    $areaProviders = [];
    if ($jsOn) $areaProviders['jubelio_shipment'] = 'Jubelio';
    if ($btOn) $areaProviders['biteship'] = 'Biteship';
    if ($kaOn) $areaProviders['kiriminaja'] = 'KiriminAja';

    // Keranjang yang tersimpan di percakapan. Alamat & tarif adalah hasil kerja
    // (mencari kecamatan, menghitung ongkir), jadi ia dipulihkan apa adanya
    // saat layar dibuka lagi — bukan dimulai dari kosong.
    $draft   = (array) ($terpilih?->order_draft['ongkir'] ?? []);
    $tujuan  = (array) ($draft['tujuan'] ?? []);
    $idArea  = (array) ($tujuan['ids'] ?? []);
@endphp

<div class="space-y-3 text-sm" x-data="ongkirCrm()"
     @hitung-ulang-ongkir.window="hitungUlang($event.detail.produk)"
     @pesanan-dibuat.window="lepasSetelahJadi()">

    {{-- --------------------------------------------------------------- asal --}}
    <div>
        <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Gudang Asal</label>
        <select x-model="gudang"
                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white">
            @forelse($gudang as $g)
                <option value="{{ $g->id }}" @selected((int) $gudangTerpilih === $g->id)>{{ $g->name }}</option>
            @empty
                <option value="">Belum ada gudang</option>
            @endforelse
        </select>
    </div>

    {{-- ------------------------------------------------------------- tujuan --}}
    <div>
        @include('partials.area-search', [
            'id'             => 'crmong',
            'url'            => route('sales.cek-ongkir.areas'),
            'hiddenName'     => 'destination_area_id',
            'label'          => 'Alamat Tujuan',
            'placeholder'    => 'Ketik kelurahan / kecamatan / kota…',
            'postalTargetId' => 'crmong_postal',
            'providers'      => $areaProviders,
            'providerField'  => 'destination_provider',
            'providerNames'  => [
                'jubelio_shipment' => 'destination_jubelio_id',
                'biteship'         => 'destination_area_id',
                'kiriminaja'       => 'destination_kiriminaja_id',
            ],
            // Alamat yang sudah dicari dipulihkan dari draft: mengetik ulang
            // kecamatan tiap kali layar dimuat adalah pekerjaan yang paling
            // sering hilang percuma di sini.
            'text'           => $tujuan['label'] ?? '',
            'value'          => $idArea[$tujuan['provider'] ?? ''] ?? '',
            'providerValues' => $idArea,
        ])
        <input type="hidden" id="crmong_postal" value="{{ $tujuan['postal'] ?? '' }}">
    </div>

    {{-- ------------------------------------------------------ titik lokasi --}}
    <div>
        <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
            Titik Lokasi <span class="font-normal normal-case text-gray-400">— opsional</span>
        </label>
        <div class="flex gap-1">
            <input type="text" x-model="titik" placeholder="Link Maps atau -7.79,110.36"
                   class="flex-1 min-w-0 border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
            <button type="button" @click="lacak()" :disabled="sibukLacak"
                    class="shrink-0 px-2 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold disabled:opacity-60">
                <span x-show="!sibukLacak">Lacak</span>
                <span x-show="sibukLacak" x-cloak>…</span>
            </button>
        </div>
        <p class="text-[11px] mt-1" :class="titikGalat ? 'text-red-600' : 'text-gray-400'"
           x-text="titikPesan || 'Kosong = kurir reguler saja. Diisi = reguler + instant.'"></p>
    </div>

    {{-- ------------------------------------------------------------ produk --}}
    <div>
        <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
            Produk <span class="font-normal normal-case text-gray-400">— beratnya dijumlah otomatis</span>
        </label>

        <div class="relative">
            <input type="text" x-model="cari" @input.debounce.300ms="cariProduk()"
                   @focus="cariProduk()" placeholder="Cari nama atau SKU…"
                   class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">

            <div x-show="hasil.length" x-cloak @click.outside="hasil = []"
                 class="absolute z-20 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-56 overflow-y-auto">
                <template x-for="p in hasil" :key="p.id">
                    <button type="button" @click="tambahProduk(p)"
                            class="w-full text-left px-2 py-1.5 hover:bg-gray-50 border-b border-gray-50 last:border-0">
                        <div class="text-xs font-medium truncate" x-text="p.name"></div>
                        <div class="text-[11px] text-gray-500">
                            <span x-text="p.sku"></span>
                            <span x-show="p.weight_gram > 0" x-text="' · ' + p.weight_gram + ' g'"></span>
                            {{-- Produk tanpa berat ditandai di DAFTAR, bukan setelah
                                 dipilih: ongkir yang dihitung dari berat nol terlihat
                                 sah-sah saja di layar dan baru ketahuan salah setelah
                                 dijanjikan ke pelanggan. --}}
                            <span x-show="!p.weight_gram" class="text-amber-600"> · berat belum diisi</span>
                        </div>
                    </button>
                </template>
            </div>
        </div>

        <div x-show="baris.length" x-cloak class="mt-2 space-y-1">
            <template x-for="(b, i) in baris" :key="b.key">
                <div class="flex items-center gap-1 text-xs">
                    <span class="flex-1 min-w-0 truncate" :title="b.nama" x-text="b.nama"></span>
                    <input type="number" min="1" x-model.number="b.qty" @input="hitungBerat()"
                           class="w-12 border border-gray-300 rounded px-1 py-0.5 text-center">
                    <span class="w-16 text-right text-gray-500" x-text="(b.berat * b.qty) + ' g'"></span>
                    <button type="button" @click="buangBaris(i)"
                            class="text-gray-400 hover:text-red-600 px-1" title="Buang">&times;</button>
                </div>
            </template>
        </div>
    </div>

    {{-- ------------------------------------------------------ berat & dimensi --}}
    <div class="flex items-end gap-2">
        <div class="flex-1">
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Berat (gram)</label>
            {{-- Bisa ditimpa manual: paket gabungan, bubble wrap tebal, atau
                 produk yang beratnya memang belum diisi di master. --}}
            <input type="number" min="1" x-model.number="berat"
                   class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
        </div>
        <div class="flex-1">
            <label class="block text-[11px] font-bold text-gray-500 uppercase mb-1">
                Dimensi <span class="font-normal normal-case text-gray-400">cm</span>
            </label>
            <div class="flex items-center gap-0.5">
                <input type="number" step="0.01" min="0" x-model.number="p" placeholder="P"
                       class="w-full min-w-0 border border-gray-300 rounded px-1 py-1.5 text-sm text-center">
                <span class="text-gray-300 text-xs">×</span>
                <input type="number" step="0.01" min="0" x-model.number="l" placeholder="L"
                       class="w-full min-w-0 border border-gray-300 rounded px-1 py-1.5 text-sm text-center">
                <span class="text-gray-300 text-xs">×</span>
                <input type="number" step="0.01" min="0" x-model.number="t" placeholder="T"
                       class="w-full min-w-0 border border-gray-300 rounded px-1 py-1.5 text-sm text-center">
            </div>
        </div>
    </div>

    <button type="button" @click="cek()" :disabled="sibuk"
            class="w-full py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold disabled:opacity-60">
        <span x-show="!sibuk">Cek Ongkir</span>
        <span x-show="sibuk" x-cloak>Menghitung…</span>
    </button>

    {{-- ------------------------------------------------------------- hasil --}}
    <div x-show="galat.length" x-cloak class="rounded border border-red-300 bg-red-50 px-2 py-1.5 text-xs text-red-700">
        <template x-for="g in galat" :key="g"><div x-text="g"></div></template>
    </div>

    {{-- Daftar tarif MENGKERUT begitu satu dipilih. Belasan baris kurir yang
         tetap menganga di kolom 380px mendorong panel "Ongkir Terpilih" —
         satu-satunya yang masih perlu disentuh — turun ke luar layar. --}}
    {{-- Tarif DIKELOMPOKKAN per jenis, bukan diurut harga saja.
         Instant dan reguler bukan pilihan sejenis: yang satu sampai dalam
         hitungan jam dengan jendela ambil pendek, yang lain berhari-hari.
         Berselang-seling dalam satu daftar membuat keduanya terbaca seolah
         setara, dan operator memilih yang termurah tanpa sadar ia baru saja
         menjanjikan kurir on-demand — atau sebaliknya. --}}
    <div x-show="tarif.length && !dipilih" x-cloak class="space-y-2">
        <template x-for="k in kelompok()" :key="k.kunci">
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-2 py-1 text-[10px] font-bold uppercase tracking-wide"
                     :class="k.instant ? 'bg-orange-50 text-orange-700' : 'bg-gray-50 text-gray-500'"
                     x-text="k.judul"></div>

                <div class="divide-y divide-gray-100">
                    <template x-for="r in k.baris" :key="r.kunci">
                        <div class="px-2 py-1.5">
                            <div class="flex items-baseline justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-xs font-semibold truncate" x-text="r.nama"></div>
                                    <div class="text-[11px] text-gray-500 truncate" x-text="r.etd"></div>
                                </div>
                                <div class="shrink-0 text-xs font-bold" x-text="r.harga"></div>
                            </div>
                            <div class="mt-1 flex items-center gap-3">
                                {{-- Menyalin ongkir ke kotak ketik, bukan langsung
                                     mengirimnya: kalimat pengantarnya hampir selalu
                                     perlu disesuaikan dulu. --}}
                                <button type="button" @click="sisipKeChat(r)"
                                        class="text-[11px] text-emerald-700 underline hover:no-underline">
                                    Sisipkan ke balasan
                                </button>
                                <button type="button" @click="pilih(r)"
                                        class="text-[11px] text-blue-700 underline hover:no-underline">
                                    Pilih
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- ---------------------------------------------------- ongkir terpilih --}}
    {{-- Diskon ongkir tinggal di sini, bukan di penyusun pesanan: yang tahu
         tarif aslinya berapa cuma layar ini, dan potongan yang diketik jauh
         dari angka asalnya gampang jadi salah tanpa ada yang menyadari. --}}
    <div x-show="dipilih" x-cloak class="rounded-lg border border-blue-200 bg-blue-50/60 p-2 space-y-2">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-blue-800 uppercase">Ongkir Terpilih</div>
                <div class="flex items-center gap-1">
                    <span x-show="dipilih?.instant" x-cloak
                          class="shrink-0 text-[10px] font-bold px-1 py-0.5 rounded bg-orange-100 text-orange-700">
                        INSTANT
                    </span>
                    <span class="text-xs truncate" x-text="dipilih?.nama"></span>
                </div>
                {{-- Tarif & lama kirim ikut ditampilkan di sini: daftarnya sudah
                     mengkerut, jadi ini satu-satunya tempat keduanya terbaca. --}}
                <div class="text-[11px] text-gray-500">
                    <span x-text="dipilih?.harga"></span>
                    <span x-show="dipilih?.etd" x-text="' · ' + dipilih?.etd"></span>
                </div>
            </div>
            <button type="button" @click="dipilih = null; terkirim = false"
                    class="shrink-0 text-[11px] text-blue-700 underline hover:no-underline"
                    title="Kembali ke daftar kurir">Ganti</button>
        </div>

        {{-- Diskon ongkir TIDAK diputuskan di sini, melainkan di segmen Ongkir
             pada tab Pesanan. Syarat potongan ongkir hampir selalu bertingkat
             ("belanja di atas 300rb ongkir 10rb"), dan layar ini tidak tahu
             berapa total belanjanya — menaruh kotaknya di sini berarti mengetik
             angka tanpa dasar yang bisa dilihat. --}}
        <p class="text-[11px] text-gray-500">
            Potongan ongkir diisi di tab <b>Pesanan</b> — di sana total belanjanya terlihat,
            dan promo ongkir bertingkat ikut terhitung sendiri.
        </p>

        <button type="button" @click="keProduk()"
                class="w-full py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold">
            Tambahkan ke Pesanan
        </button>
        <p x-show="terkirim" x-cloak class="text-[11px] text-emerald-700">
            Sudah masuk ke tab Pesanan.
        </p>
    </div>

    <p x-show="sudahCek && !tarif.length && !galat.length" x-cloak class="text-xs text-gray-500">
        Tidak ada layanan kurir untuk tujuan &amp; berat ini.
    </p>
</div>

<script>
    function ongkirCrm() {
        // Keadaan tersimpan dari percakapan; kosong berarti mulai baru.
        const awal = @json($draft ?: new stdClass);

        return {
            gudang: awal.gudang ?? '{{ (int) $gudangTerpilih }}',
            titik: awal.titik ?? '', titikPesan: awal.titikPesan ?? '', titikGalat: false, sibukLacak: false,
            lat: awal.lat ?? null, lng: awal.lng ?? null,

            cari: '', hasil: [], baris: awal.baris ?? [], nomor: (awal.baris ?? []).length,
            berat: awal.berat ?? 1000, p: awal.p ?? '', l: awal.l ?? '', t: awal.t ?? '',

            sibuk: false, sudahCek: false, tarif: [], galat: [],
            dipilih: awal.dipilih ?? null,
            terkirim: false,
            jamSimpan: null,

            init() {
                // Tiap medan yang jadi bagian SO ikut disimpan. Alamat tujuan
                // dikelola partial area-search lewat DOM (bukan Alpine), jadi
                // perubahannya dijaring dari kotak ketiknya sendiri.
                ['gudang', 'titik', 'lat', 'lng', 'berat', 'p', 'l', 't',
                 'baris', 'dipilih']
                    .forEach(k => this.$watch(k, () => this.simpanNanti()));

                document.getElementById('crmong_search')
                    ?.addEventListener('input', () => this.simpanNanti());
            },

            /*
             * Disimpan setelah mengetik berhenti sejenak, bukan tiap ketukan:
             * kotak berat & pencarian alamat berubah puluhan kali per detik dan
             * menembakkan permintaan sebanyak itu tak ada gunanya.
             */
            simpanNanti() {
                clearTimeout(this.jamSimpan);
                this.jamSimpan = setTimeout(() => this.simpan(), 800);
            },

            simpan() {
                const prov = document.getElementById('crmong_provider')?.value ?? null;

                const ids = {};
                for (const kode of ['jubelio_shipment', 'biteship', 'kiriminaja']) {
                    const el = document.getElementById('crmong_' + kode + '_id');
                    if (el?.value) ids[kode] = el.value;
                }

                const utama = document.getElementById('crmong_id')?.value;
                if (utama && prov && !ids[prov]) ids[prov] = utama;

                fetch('{{ $terpilih ? route('crm.inbox.draft-pesanan', $terpilih) : '' }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        bagian: 'ongkir',
                        data: {
                            gudang: this.gudang,
                            titik: this.titik, titikPesan: this.titikPesan, lat: this.lat, lng: this.lng,
                            berat: this.berat, p: this.p, l: this.l, t: this.t,
                            baris: this.baris,
                            dipilih: this.dipilih,
                            tujuan: {
                                label: document.getElementById('crmong_search')?.value ?? '',
                                provider: prov,
                                ids,
                                postal: document.getElementById('crmong_postal')?.value ?? '',
                            },
                        },
                    }),
                // Gagal simpan TIDAK diteriakkan: draft cuma kenyamanan, dan
                // pita merah di tengah menyusun pesanan lebih mengganggu
                // daripada kehilangan satu simpanan yang akan terulang 800ms lagi.
                }).catch(() => {});
            },

            /* ------------------------------------------------------- produk */

            async cariProduk() {
                const q = this.cari.trim();

                if (q.length < 2) { this.hasil = []; return; }

                try {
                    // sellable_only: yang dikirim ke pelanggan pasti barang jual.
                    const r = await fetch('/erp/api/products/search?sellable_only=1&q=' + encodeURIComponent(q),
                        { headers: { 'Accept': 'application/json' } });

                    this.hasil = r.ok ? await r.json() : [];
                } catch (e) { this.hasil = []; }
            },

            tambahProduk(p) {
                // id & harga ikut disimpan meski panel ini cuma butuh berat:
                // barisnya nanti pindah utuh ke keranjang pesanan, dan tanpa
                // keduanya produk harus dicari ulang di sana.
                this.masukkan({
                    id: p.id, nama: p.name,
                    harga: Number(p.price) || 0,
                    berat: Number(p.weight_gram) || 0,
                    qty: 1,
                    dim: [Number(p.length_cm) || 0, Number(p.width_cm) || 0, Number(p.height_cm) || 0],
                });

                this.cari = '';
                this.hasil = [];
                this.hitungBerat();
            },

            /* Produk yang sudah ada tidak digandakan barisnya — jumlahnya ditambah. */
            masukkan(p) {
                const ada = this.baris.find(b => b.id === p.id);

                if (ada) { ada.qty = (Number(ada.qty) || 0) + (Number(p.qty) || 1); return; }

                this.baris.push({
                    key: ++this.nomor,
                    id: p.id, nama: p.nama,
                    harga: p.harga ?? 0,
                    berat: p.berat ?? 0,
                    qty: p.qty || 1,
                    dim: p.dim ?? [0, 0, 0],
                });
            },

            /*
             * Keranjang dari tab Pesanan menggantikan isi panel ini, bukan
             * ditambahkan: yang diminta adalah menghitung ulang ongkir UNTUK
             * keranjang itu, jadi sisa produk percobaan sebelumnya justru
             * membuat beratnya salah.
             *
             * Tarif yang sudah dipilih ikut dilepas — ia dihitung untuk berat
             * yang sekarang sudah berubah, dan membiarkannya terpampang membuat
             * angka basi terlihat seperti masih berlaku.
             */
            /*
             * Pesanan sudah jadi SO — tarif ini sudah pindah ke sana dan bukan
             * lagi urusan layar ini. Yang dilepas: tarif terpilih, diskonnya,
             * dan produk penimbangnya. Yang DITAHAN: alamat tujuan & gudang asal
             * — alamat pelanggan tidak berubah antar pesanan, dan mengetiknya
             * ulang tiap kali cuma memancing salah ketik.
             *
             * Mulai titik ini, ongkir pesanan itu hanya bisa diubah lewat
             * halaman SO-nya, dengan aturan SO biasa.
             */
            lepasSetelahJadi() {
                this.dipilih = null;
                this.terkirim = false;
                this.tarif = [];
                this.baris = [];
                this.berat = 1000;
                this.p = this.l = this.t = '';
                this.sudahCek = false;
            },

            hitungUlang(produk) {
                this.baris = (produk ?? []).map(p => ({
                    key: ++this.nomor,
                    id: p.id, nama: p.nama,
                    harga: p.harga ?? 0,
                    berat: p.berat ?? 0,
                    qty: p.qty || 1,
                    dim: [0, 0, 0],
                }));

                this.dipilih = null;
                this.terkirim = false;
                this.tarif = [];
                this.hitungBerat();
            },

            buangBaris(i) {
                this.baris.splice(i, 1);
                this.hitungBerat();
            },

            /*
             * Berat = jumlah semua baris. Dimensi diambil dari baris TERBESAR,
             * bukan dijumlah: menumpuk dua kotak tidak membuat paketnya dua kali
             * lebih panjang, dan dimensi di sini cuma penentu kelas kendaraan
             * untuk kurir instant.
             */
            hitungBerat() {
                if (!this.baris.length) return;

                this.berat = this.baris.reduce((n, b) => n + (b.berat * (b.qty || 1)), 0) || 1;

                const besar = this.baris.reduce((a, b) =>
                    (b.dim[0] * b.dim[1] * b.dim[2]) > (a[0] * a[1] * a[2]) ? b.dim : a, [0, 0, 0]);

                if (besar[0] > 0) { this.p = besar[0]; this.l = besar[1]; this.t = besar[2]; }
            },

            /* ------------------------------------------------ titik lokasi */

            async lacak() {
                const titik = this.titik.trim();

                if (!titik) { this.setTitik('Paste link Google Maps atau "lat,long" dulu.', true); return; }

                this.sibukLacak = true;

                try {
                    const r = await fetch('{{ route('sales.cek-ongkir.resolve') }}?point=' + encodeURIComponent(titik),
                        { headers: { 'Accept': 'application/json' } });
                    const d = await r.json();

                    if (!d.success) { this.setTitik(d.error || 'Koordinat tidak terbaca.', true); return; }

                    this.lat = d.latitude;
                    this.lng = d.longitude;

                    /*
                     * Alamat hasil lacak IKUT mengisi kotak tujuan. Kalau tidak,
                     * admin sudah menemukan titiknya tapi tetap harus mengetik
                     * ulang kecamatannya untuk kurir reguler — dan dua isian itu
                     * bisa jadi tidak menunjuk tempat yang sama.
                     */
                    if (d.area_id) this.pasangArea(d.area_id, d.area_label, d.postal_code);

                    this.setTitik(d.address || 'Titik terkunci — kurir instant ikut dihitung.', false);
                } catch (e) {
                    this.setTitik('Jaringan bermasalah — titik belum terlacak.', true);
                } finally {
                    this.sibukLacak = false;
                }
            },

            setTitik(pesan, galat) {
                this.titikPesan = pesan;
                this.titikGalat = galat;
                if (galat) { this.lat = this.lng = null; }
            },

            // Partial area-search dikendalikan lewat DOM (bukan Alpine), jadi
            // isiannya disetel langsung ke elemen yang sama yang dibaca cek().
            pasangArea(id, label, kodePos) {
                const cari  = document.getElementById('crmong_search');
                const utama = document.getElementById('crmong_id');
                const pos   = document.getElementById('crmong_postal');

                if (cari && label) cari.value = label;
                if (utama) utama.value = id;
                if (pos && kodePos) pos.value = kodePos;

                const prov = document.getElementById('crmong_provider');
                const perProvider = prov && document.getElementById('crmong_' + prov.value + '_id');
                if (perProvider) perProvider.value = id;
            },

            /* --------------------------------------------------------- cek */

            /*
             * Tanpa titik lokasi: kurir REGULER saja.
             * Dengan titik lokasi: reguler DAN instant, dalam satu daftar,
             * masing-masing bertanda.
             *
             * Dua permintaan terpisah, bukan satu: provider memisahkan tarif
             * on-demand dari tarif reguler lewat parameter mode, dan meminta
             * mode instant saja membuat pilihan reguler ikut hilang dari layar —
             * padahal untuk kiriman luar kota justru itu yang dipakai.
             *
             * Keduanya berjalan berbarengan dan dinilai SENDIRI-SENDIRI:
             * instant gampang gagal karena syaratnya lebih ketat (gudang wajib
             * punya koordinat), dan kegagalannya tidak boleh ikut menghapus
             * tarif reguler yang sudah berhasil didapat.
             */
            async cek() {
                if (this.sibuk) return;

                this.sibuk = true;
                this.galat = [];
                this.tarif = [];

                const adaTitik = this.lat !== null && this.lng !== null;

                const permintaan = [this.mintaTarif(false)];
                if (adaTitik) permintaan.push(this.mintaTarif(true));

                try {
                    const hasil = await Promise.all(permintaan);

                    const semua = [];
                    const galat = [];

                    for (const h of hasil) {
                        h.rates.forEach(x => semua.push({ ...x, __instant: h.instant }));
                        // Galat instant diberi awalan supaya jelas bagian mana
                        // yang gagal — daftar regulernya mungkin baik-baik saja.
                        h.errors.forEach(g => galat.push((h.instant ? 'Instant: ' : '') + g));
                    }

                    // Termurah di atas, tanpa memandang jenisnya: yang dicari
                    // operator hampir selalu angka, bukan golongan kurirnya.
                    semua.sort((a, b) => (Number(a.price) || 0) - (Number(b.price) || 0));

                    this.galat = galat;
                    this.tarif = semua.map((x, i) => ({
                        kunci: i,
                        nama:  [x.courier_name ?? x.courier ?? '', x.service_name ?? x.service ?? ''].filter(Boolean).join(' · '),
                        etd:   x.etd ?? x.duration ?? '',
                        instant: !!x.__instant,
                        harga: 'Rp ' + Number(x.price ?? 0).toLocaleString('id-ID'),
                        // Angka mentahnya disimpan terpisah dari yang diformat:
                        // memparsing "Rp 22.000" balik jadi bilangan adalah cara
                        // paling gampang kehilangan digit di lokal Indonesia.
                        harga_angka: Number(x.price ?? 0),
                        mentah: x,
                    }));
                } catch (e) {
                    this.galat = ['Jaringan bermasalah — ongkir belum terhitung.'];
                } finally {
                    this.sibuk = false;
                    this.sudahCek = true;
                    // Alamat tujuan baru benar-benar "jadi" setelah dipakai
                    // menghitung; inilah saat paling layak diabadikan.
                    this.simpanNanti();
                }
            },

            /*
             * Instant lebih dulu KARENA ia hanya muncul saat operator sengaja
             * mengisi titik lokasi — daftarnya pendek, dan menaruhnya di bawah
             * berarti hasil dari tindakan yang baru saja dilakukan justru
             * tersembunyi di ujung gulir. Reguler menyusul, tetap lengkap.
             */
            kelompok() {
                const grup = [
                    { kunci: 'instant', judul: 'Instant · sampai hitungan jam', instant: true,
                      baris: this.tarif.filter(r => r.instant) },
                    { kunci: 'reguler', judul: 'Reguler', instant: false,
                      baris: this.tarif.filter(r => !r.instant) },
                ];

                return grup.filter(g => g.baris.length);
            },

            /** Satu permintaan tarif. @return {rates, errors, instant} */
            async mintaTarif(instant) {
                const d = new FormData();
                d.append('warehouse_id', this.gudang);
                d.append('weight_gram', this.berat || 1);

                if (instant) {
                    d.append('mode', 'instant');
                    d.append('destination_latitude', this.lat);
                    d.append('destination_longitude', this.lng);
                }

                for (const [medan, el] of Object.entries({
                    destination_provider:      document.getElementById('crmong_provider'),
                    destination_area_id:       document.getElementById('crmong_biteship_id') || document.getElementById('crmong_id'),
                    destination_kiriminaja_id: document.getElementById('crmong_kiriminaja_id'),
                    destination_jubelio_id:    document.getElementById('crmong_jubelio_shipment_id'),
                    destination_postal_code:   document.getElementById('crmong_postal'),
                })) {
                    if (el?.value) d.append(medan, el.value);
                }

                for (const [medan, nilai] of Object.entries({
                    package_length: this.p, package_width: this.l, package_height: this.t,
                })) {
                    if (nilai) d.append(medan, nilai);
                }

                try {
                    const r = await fetch('{{ route('sales.cek-ongkir.check') }}', {
                        method: 'POST',
                        body: d,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        },
                    });
                    const j = await r.json().catch(() => ({}));

                    if (!r.ok) {
                        // 422 dari validate() memakai bentuk 'errors' per medan.
                        return {
                            rates: [], instant,
                            errors: j.errors ? Object.values(j.errors).flat() : [j.message || 'Gagal menghitung ongkir.'],
                        };
                    }

                    return { rates: j.rates ?? [], errors: j.errors ?? [], instant };
                } catch (e) {
                    return { rates: [], errors: ['Jaringan bermasalah.'], instant };
                }
            },

            /* ------------------------------------------------ ke pesanan */

            pilih(r) {
                this.dipilih = r;
                this.terkirim = false;
            },


            keProduk() {
                const r = this.dipilih;
                if (!r) return;

                window.dispatchEvent(new CustomEvent('ongkir-dipilih', {
                    detail: {
                        ongkir: {
                            nama:           r.nama,
                            // Yang menyeberang cuma tarif KOTOR; potongannya
                            // diputuskan di tab Pesanan, tempat total belanja
                            // (syarat promo ongkir bertingkat) terlihat.
                            gross:          Number(r.harga_angka) || 0,
                            instant:        !!r.instant,
                            provider:       r.mentah?.provider ?? null,
                            courier_code:   r.mentah?.courier_code ?? null,
                            service_code:   r.mentah?.service_code ?? null,
                        },
                        // Produk penimbang ikut menyeberang: yang ditimbang di
                        // sini memang barang yang mau dipesan, dan mengetiknya
                        // ulang di tab sebelah cuma kesempatan untuk salah.
                        produk: this.baris.map(b => ({
                            id: b.id, nama: b.nama, harga: b.harga, qty: b.qty, berat: b.berat,
                        })).filter(b => b.id),
                    },
                }));

                this.terkirim = true;

                /*
                 * Langsung pindah ke tab Pesanan. Tab-nya milik komponen rail di
                 * atas panel ini, jadi disenggol lewat peristiwa — panel ongkir
                 * tidak perlu tahu ada tab bernama apa saja di sana, dan `this`
                 * di dalam metode komponen ini memang tidak menjangkau data
                 * induknya.
                 */
                window.dispatchEvent(new CustomEvent('buka-tab', { detail: { tab: 'pesanan' } }));
            },

            // Menumpang peristiwa yang sama dengan potongan balasan, jadi kotak
            // ketik tak perlu tahu ada panel baru di sebelahnya.
            sisipKeChat(r) {
                window.dispatchEvent(new CustomEvent('sisip-snippet', {
                    detail: { teks: 'Ongkir ' + r.nama + ': ' + r.harga + (r.etd ? ' (' + r.etd + ')' : '') },
                }));
            },
        };
    }
</script>
