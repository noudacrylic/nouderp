{{-- Popup "Mulai Chat" — memulai percakapan dengan template BERBAYAR Meta.

     Popup, bukan halaman: dua pintu masuknya (tombol ＋ di kepala daftar, dan
     "Mulai chat" di kotak jendela-tertutup) sama-sama muncul di tengah kerja,
     dan pindah halaman berarti kehilangan thread yang sedang dibaca beserta
     seluruh filter kolom kirinya.

     Yang dikirim tetap POST ke jalur yang sama dengan layar Chat Baru —
     bunyinya diambil dari basis data, bukan dari form, jadi popup ini tidak
     membuka jalan mengirim kalimat karangan sendiri sebagai template. --}}
<div x-data="mulaiChatCrm(@js($templateMeta->map(fn ($t) => [
        'id'        => $t->id,
        'nama'      => $t->title,
        'meta'      => $t->meta_name,
        'body'      => $t->body,
        'variables' => $t->jumlahVariabel(),
     ])->values()), @js(route('crm.kontak.cari')))"
     @mulai-chat.window="buka($event.detail)"
     x-cloak>

    <div x-show="tampil" class="fixed inset-0 z-50 lapis-layar flex items-start justify-center p-4 pt-16 overflow-y-auto"
         @keydown.escape.window="tutup()">
        <div class="absolute inset-0 bg-black/40" @click="tutup()"></div>

        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg">
            <div class="flex items-start justify-between gap-3 px-4 py-3 border-b border-gray-200">
                <div>
                    <div class="text-sm font-semibold">Mulai Chat</div>
                    <div class="text-[11px] text-gray-500">
                        Di luar jendela 24 jam hanya template yang sudah disetujui Meta yang boleh keluar &mdash;
                        <b>berbayar</b>. Begitu pelanggan membalas, jendelanya terbuka dan Anda bisa mengetik bebas.
                    </div>
                </div>
                <button type="button" @click="tutup()"
                        class="shrink-0 text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
            </div>

            @if($templateMeta->isEmpty())
                <div class="p-4">
                    <div class="rounded border border-amber-300 bg-amber-50 px-3 py-3 text-sm text-amber-800">
                        <b>Belum ada template yang disetujui Meta.</b>
                        Buat dulu di <a href="{{ route('crm.template.index') }}" class="text-blue-700 underline">Template Pesan</a>,
                        centang &ldquo;Daftarkan ke Meta&rdquo;, lalu tekan Ajukan.
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('crm.template.mulai') }}" class="p-4 space-y-3">
                    @csrf

                    {{-- ------------------------------------------------ tujuan --}}
                    {{-- Dibuka dari sebuah chat, nomornya sudah pasti: yang tampil
                         namanya, bukan kotak ketik yang mengundang salah ketik.
                         Dibuka dari tombol ＋, barulah nomornya dicari sendiri. --}}
                    <div x-show="terkunci">
                        <div class="text-xs text-gray-500 mb-1">Nomor Tujuan</div>
                        <div class="flex items-center gap-2 rounded border border-gray-200 bg-gray-50 px-3 py-2">
                            <div class="min-w-0">
                                <div class="text-sm font-medium truncate" x-text="nama || nomor"></div>
                                <div class="text-[11px] text-gray-500 font-mono" x-text="nomor"></div>
                            </div>
                            <button type="button" @click="bukaKunci()"
                                    class="ml-auto shrink-0 text-[11px] text-emerald-700 hover:underline">Ganti</button>
                        </div>
                    </div>

                    <div x-show="! terkunci" class="relative">
                        <label class="block text-xs text-gray-500 mb-1">Nomor tujuan atau nama kontak</label>
                        <input type="text" x-ref="cari" x-model="cari" @input.debounce.300ms="cariKontak()"
                               @focus="cariKontak()" autocomplete="off"
                               placeholder="Ketik nomor, atau cari kontak yang sudah ada…"
                               class="w-full border rounded px-3 py-2 text-sm">

                        {{-- Nomor bebas TETAP boleh: pelanggan yang meninggalkan
                             nomor di toko belum ada di mana pun. Daftar kontak
                             cuma jalan pintas, bukan pagar. --}}
                        <div x-show="cari.trim()" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-64 overflow-y-auto">
                            <template x-if="mirip(cari)">
                                <button type="button" @click="pakai({ nomor: cari, nama: '' })"
                                        class="w-full text-left px-3 py-2 border-b border-gray-100 hover:bg-gray-50">
                                    <span class="text-sm">Pakai nomor </span>
                                    <span class="text-sm font-mono font-medium" x-text="cari"></span>
                                </button>
                            </template>

                            <template x-for="k in kontak" :key="k.nomor">
                                <button type="button" @click="pakai(k)"
                                        class="w-full text-left px-3 py-2 border-b border-gray-100 last:border-0 hover:bg-gray-50">
                                    <div class="flex items-baseline gap-2">
                                        <span class="text-sm font-medium truncate" x-text="k.nama"></span>
                                        <span class="text-[10px] uppercase tracking-wide shrink-0"
                                              :class="k.sumber === 'chat' ? 'text-emerald-600' : 'text-gray-400'"
                                              x-text="k.sumber === 'chat' ? 'chat' : 'pelanggan'"></span>
                                    </div>
                                    <div class="text-[11px] text-gray-500 font-mono" x-text="k.nomor"></div>
                                </button>
                            </template>

                            <p x-show="! kontak.length && ! mirip(cari)" class="px-3 py-3 text-xs text-gray-500">
                                Tidak ada kontak yang cocok. Ketik nomornya langsung.
                            </p>
                        </div>
                    </div>

                    <input type="hidden" name="nomor" :value="nomor">

                    {{-- ---------------------------------------------- template --}}
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Template pembuka</label>
                        <select name="template" x-model="pilih" required class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">&mdash; pilih &mdash;</option>
                            <template x-for="t in daftar" :key="t.id">
                                <option :value="t.id" x-text="t.nama + ' · ' + t.meta"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Isian sebanyak variabel template, dan bunyinya ditampilkan
                         utuh — template tidak bisa ditarik kembali, jadi admin
                         wajib melihat kalimatnya SEBELUM menekan kirim. --}}
                    <template x-if="t && t.variables > 0">
                        <div class="space-y-2">
                            <template x-for="i in t.variables" :key="i">
                                <div>
                                    <label class="block text-[11px] text-gray-500 mb-1" x-text="'Isian ke-' + i"></label>
                                    <input type="text" :name="'variabel[' + (i - 1) + ']'" required
                                           class="w-full border rounded px-3 py-2 text-sm">
                                </div>
                            </template>
                        </div>
                    </template>

                    <div x-show="t">
                        <div class="text-xs text-gray-500 mb-1">Bunyi template</div>
                        <div class="rounded border bg-gray-50 px-3 py-2 text-sm whitespace-pre-wrap" x-text="t?.body"></div>
                    </div>

                    <div class="pt-1 flex justify-end gap-2">
                        <button type="button" @click="tutup()"
                                class="px-3 py-1.5 rounded border border-gray-300 text-sm hover:bg-gray-50">Batal</button>
                        {{-- Dikunci selama nomornya belum ada: tanpa ini tombolnya
                             bisa ditekan lalu ditolak server, dan yang terbaca
                             admin cuma "gagal" tanpa tahu bagian mana. --}}
                        <button :disabled="! nomor || ! pilih"
                                class="px-3 py-1.5 rounded text-sm text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed">
                            Kirim &amp; Mulai Percakapan
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

<script>
function mulaiChatCrm(daftar, urlKontak) {
    return {
        daftar, urlKontak,
        tampil: false,
        terkunci: false,
        nomor: '',
        nama: '',
        cari: '',
        kontak: [],
        pilih: '',

        get t() {
            return this.daftar.find(x => String(x.id) === String(this.pilih)) || null;
        },

        /*
         * Dibuka dengan nomor (dari kotak jendela-tertutup) berarti tujuannya
         * sudah pasti. Tanpa nomor (tombol ＋) berarti tujuannya masih dicari.
         */
        buka(detail = {}) {
            this.nomor    = detail?.nomor || '';
            this.nama     = detail?.nama  || '';
            this.terkunci = !! this.nomor;
            this.cari     = '';
            this.kontak   = [];
            this.pilih    = '';
            this.tampil   = true;

            if (! this.terkunci) this.$nextTick(() => this.$refs.cari?.focus());
        },

        tutup() {
            this.tampil = false;
        },

        bukaKunci() {
            this.terkunci = false;
            this.nomor    = '';
            this.nama     = '';
            this.$nextTick(() => this.$refs.cari?.focus());
        },

        // Cukup angka untuk disebut nomor. Bentuk apa pun boleh — servernya
        // yang menormalkan, sama seperti layar Chat Baru.
        mirip(teks) {
            return /^[0-9+][0-9\s.+-]{6,}$/.test((teks || '').trim());
        },

        async cariKontak() {
            const q = this.cari.trim();
            if (! q) { this.kontak = []; return; }

            try {
                const r = await fetch(this.urlKontak + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                });
                if (! r.ok) return;
                this.kontak = (await r.json()).hasil || [];
            } catch (e) {
                // Jaringan bermasalah bukan alasan menutup jalan: kotaknya tetap
                // menerima nomor yang diketik tangan.
                this.kontak = [];
            }
        },

        pakai(k) {
            this.nomor    = (k.nomor || '').trim();
            this.nama     = k.nama || '';
            this.terkunci = true;
            this.cari     = '';
            this.kontak   = [];
        },
    };
}
</script>
