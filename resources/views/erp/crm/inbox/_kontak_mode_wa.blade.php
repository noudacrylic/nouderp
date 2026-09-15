{{-- Kotak kontak mode WhatsApp Web — pengganti daftar percakapan.

     Operator melihat nama/nomor di jendela WhatsApp Web, mengetiknya di sini,
     lalu percakapan ERP dibuka (atau dibuat) lewat crm.inbox.buka-kontak.
     TIDAK ADA pesan yang keluar: yang dibutuhkan cuma wadah untuk draft
     pesanan & tombol Buat SO.

     Yang dipilih PELANGGAN, bukan percakapan (crm.kontak.cari?hanya=pelanggan):
     percakapan ERP di mode ini tak pernah berisi chat sungguhan. Percakapannya
     tetap dibuat diam-diam dari nomor pelanggan karena tab Pesanan menempel ke
     sana; pelanggan ikut tertaut, jadi tidak lahir pelanggan kembar saat SO.
     Pembeli yang belum jadi pelanggan dibuka dari nomornya — namanya diisi di
     tab Pesanan, yang membuat pelanggan baru saat Buat SO. --}}
<div x-data="kontakModeWa(@js(route('crm.kontak.cari', ['hanya' => 'pelanggan'])))" class="relative flex-1 min-w-0"
     @click.outside="hasil = []">

    <form x-ref="form" method="POST" action="{{ route('crm.inbox.buka-kontak') }}" class="flex items-center gap-2">
        @csrf
        <input type="hidden" name="nomor" x-model="pilihan.nomor">
        <input type="hidden" name="nama" x-model="pilihan.nama">
        <input type="hidden" name="customer_id" x-model="pilihan.customer_id">

        <input type="text" x-model="cari" x-ref="cari"
               @input.debounce.300ms="cariKontak()"
               @keydown.enter.prevent="enter()"
               @keydown.escape="hasil = []"
               placeholder="Cari pelanggan (nama / No. HP)…"
               class="flex-1 min-w-0 px-2 py-1 rounded border border-gray-300 text-xs focus:border-emerald-500 focus:ring-emerald-500">

        <button type="button" @click="bukaFormBaru()" title="Tambah pelanggan baru"
                class="shrink-0 px-2 py-1 rounded border border-emerald-600 text-emerald-700 bg-white hover:bg-emerald-50">
            ＋ Pelanggan
        </button>

    </form>

    {{-- Baris sendiri di bawah kotak cari, bukan sebaris: di layar setengah
         monitor sebaris membuat nama terpotong tinggal satu huruf. --}}
    <div class="mt-1 text-gray-600 break-words">
        @if($terpilih)
            <b>{{ $terpilih->namaTampil() }}</b>
            <span class="text-gray-500">· {{ $terpilih->contact_key }}</span>
        @else
            <span class="text-gray-400">Belum ada pelanggan dipilih.</span>
        @endif
    </div>

    <div x-show="hasil.length || nomorBaru()" x-cloak
         class="absolute z-30 mt-1 w-72 max-h-72 overflow-y-auto rounded border border-gray-200 bg-white shadow-lg">
        <template x-for="k in hasil" :key="k.sumber + k.nomor">
            <button type="button" @click="buka(k)"
                    class="w-full text-left px-3 py-2 hover:bg-emerald-50 border-b border-gray-100">
                <div class="font-medium text-gray-800 truncate" x-text="k.nama"></div>
                <div class="text-[11px] text-gray-500" x-text="k.nomor"></div>
            </button>
        </template>

        {{-- Nomor asing yang belum pernah ada di ERP: paling sering terjadi,
             karena di mode ini chat masuk tidak pernah tercatat. --}}
        <button type="button" x-show="nomorBaru()" @click="bukaNomor()"
                class="w-full text-left px-3 py-2 hover:bg-emerald-50 text-emerald-700">
            Pembeli baru: <b x-text="cari.trim()"></b>
            <span class="block text-[11px] text-gray-500">Nama diisi di tab Pesanan saat Buat SO.</span>
        </button>
    </div>

    {{-- Tambah pelanggan: memakai /erp/customers/store-ajax yang sama dengan
         "Tambah Pelanggan Cepat" di form SO, jadi aturan kode & hadangan nama
         kembar tidak bercabang. No. HP WAJIB di sini (di form SO opsional):
         tanpa nomor tidak ada wadah untuk tab Pesanan. --}}
    <div x-show="formBaru" x-cloak @keydown.escape.window="formBaru = false"
         class="absolute z-40 mt-1 w-80 rounded border border-gray-200 bg-white shadow-lg p-3 space-y-2">
        <div class="flex items-center justify-between">
            <div class="text-sm font-semibold">Pelanggan baru</div>
            <button type="button" @click="formBaru = false" class="text-gray-400 hover:text-gray-700 text-lg leading-none">&times;</button>
        </div>

        <label class="block">
            <span class="text-[11px] text-gray-600">Nama</span>
            <input type="text" x-model="baru.nama" x-ref="namaBaru" @keydown.enter.prevent="simpanBaru(false)"
                   class="w-full px-2 py-1 rounded border border-gray-300 text-sm">
        </label>
        <label class="block">
            <span class="text-[11px] text-gray-600">No. HP / WhatsApp</span>
            <input type="text" x-model="baru.hp" inputmode="tel" placeholder="08…" @keydown.enter.prevent="simpanBaru(false)"
                   class="w-full px-2 py-1 rounded border border-gray-300 text-sm">
        </label>

        <p x-show="galat" x-text="galat" class="text-xs text-red-600"></p>

        {{-- Nama kembar: tawarkan yang sudah ada dulu, simpan baru hanya bila ditegaskan. --}}
        <div x-show="kembar.length" class="rounded border border-amber-300 bg-amber-50 p-2 text-xs space-y-1">
            <div class="text-amber-800">Nama ini sudah ada. Pelanggan yang sama?</div>
            <template x-for="c in kembar" :key="c.id">
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate" x-text="c.label"></span>
                    <button type="button" x-show="c.phone" @click="bukaPelanggan(c)"
                            class="shrink-0 px-2 py-0.5 rounded border border-gray-300 bg-white hover:bg-gray-50">Pakai</button>
                </div>
            </template>
            <button type="button" @click="simpanBaru(true)" class="underline text-amber-900">Bukan, tetap simpan baru</button>
        </div>

        <div class="flex justify-end">
            <button type="button" @click="simpanBaru(false)" :disabled="menyimpan"
                    class="px-3 py-1 rounded bg-emerald-600 text-white text-sm hover:bg-emerald-700 disabled:opacity-50"
                    x-text="menyimpan ? 'Menyimpan…' : 'Simpan & pilih'"></button>
        </div>
    </div>
</div>

<script>
    function kontakModeWa(urlKontak) {
        return {
            cari: '', hasil: [],
            pilihan: { nomor: '', nama: '', customer_id: '' },

            mirip(teks) {
                return /^[0-9+][0-9\s.+-]{7,}$/.test((teks || '').trim());
            },

            angka(teks) {
                return (teks || '').replace(/\D+/g, '').replace(/^0/, '62');
            },

            // Tawaran "kontak baru" hanya muncul kalau nomor itu belum ada di hasil.
            nomorBaru() {
                if (! this.mirip(this.cari)) return false;
                const n = this.angka(this.cari);
                return ! this.hasil.some(k => this.angka(k.nomor) === n);
            },

            async cariKontak() {
                const q = this.cari.trim();
                if (q.length < 2) { this.hasil = []; return; }

                try {
                    // urlKontak sudah membawa ?hanya=pelanggan — sambungannya '&', bukan '?'.
                    const sambung = urlKontak.includes('?') ? '&' : '?';
                    const r = await fetch(urlKontak + sambung + 'q=' + encodeURIComponent(q), {
                        headers: { 'Accept': 'application/json' },
                    });
                    this.hasil = r.ok ? ((await r.json()).hasil || []) : [];
                } catch (e) {
                    // Jaringan putus bukan alasan menutup jalan: nomor ketikan tetap bisa dibuka.
                    this.hasil = [];
                }
            },

            enter() {
                if (this.hasil.length === 1 && ! this.nomorBaru()) return this.buka(this.hasil[0]);
                if (this.mirip(this.cari)) return this.bukaNomor();
            },

            buka(k) {
                // Nama hasil 'chat' TIDAK dikirim: itu nama tampil yang sudah ada,
                // dan mengirimnya akan mengubah nama profil WhatsApp jadi nama manual.
                this.pilihan = {
                    nomor: k.nomor,
                    nama: k.sumber === 'chat' ? '' : (k.nama || ''),
                    customer_id: k.customer_id || '',
                };
                this.$nextTick(() => this.$refs.form.submit());
            },

            formBaru: false, menyimpan: false, galat: '', kembar: [],
            baru: { nama: '', hp: '' },

            // Ketikan di kotak cari ikut terbawa ke kolom yang cocok.
            bukaFormBaru() {
                const q = this.cari.trim();
                this.baru    = this.mirip(q) ? { nama: '', hp: q } : { nama: q, hp: '' };
                this.galat   = '';
                this.kembar  = [];
                this.hasil   = [];
                this.formBaru = true;
                this.$nextTick(() => this.$refs.namaBaru?.focus());
            },

            async simpanBaru(paksa) {
                this.galat  = '';
                if (! this.baru.nama.trim()) { this.galat = 'Nama wajib diisi.'; return; }
                if (! this.mirip(this.baru.hp)) { this.galat = 'No. HP wajib diisi dengan benar.'; return; }

                this.menyimpan = true;
                try {
                    const r = await fetch('{{ url('/erp/customers/store-ajax') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify({ name: this.baru.nama.trim(), phone: this.baru.hp.trim(), force: paksa }),
                    });
                    const j = await r.json().catch(() => ({}));

                    if (r.status === 409 && j.duplicate) {
                        this.kembar = j.existing || [];
                        return;
                    }
                    if (! r.ok) {
                        this.galat = j.message || 'Gagal menyimpan pelanggan.';
                        return;
                    }

                    this.bukaPelanggan(j);
                } catch (e) {
                    this.galat = 'Jaringan bermasalah, coba lagi.';
                } finally {
                    this.menyimpan = false;
                }
            },

            bukaPelanggan(c) {
                this.pilihan = { nomor: c.phone, nama: '', customer_id: c.id };
                this.$nextTick(() => this.$refs.form.submit());
            },

            bukaNomor() {
                this.pilihan = { nomor: this.cari.trim(), nama: '', customer_id: '' };
                this.$nextTick(() => this.$refs.form.submit());
            },
        };
    }
</script>
