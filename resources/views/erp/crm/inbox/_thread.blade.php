{{-- Kolom TENGAH: percakapan + kotak balasan. Dipisah jadi partial supaya
     kolom kiri & rail kanan tidak ikut disusun ulang tiap kali thread dibuka. --}}
@php $terbuka = $terpilih->windowIsOpen(); @endphp

{{-- ------------------------------------------------------------ percakapan --}}
<div class="flex flex-col h-full min-h-0 bg-white border border-gray-200 rounded-lg overflow-hidden"
     x-data="threadCrm({{ $terpilih->id }}, {{ (int) ($pesan->max('id') ?? 0) }})">

    {{-- Kepala percakapan: siapa yang sedang dibalas, selalu terlihat walau
         thread digulir jauh ke atas. Tanpa ini admin yang membuka beberapa
         chat berturut-turut kehilangan jejak sedang bicara dengan siapa. --}}
    <div class="shrink-0 flex items-center gap-3 px-4 py-2.5 border-b border-gray-200 bg-white">
        <div class="w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center font-semibold shrink-0">
            {{ mb_strtoupper(mb_substr($terpilih->customer->name ?? $terpilih->display_name ?? $terpilih->contact_key, 0, 1)) }}
        </div>
        <div class="min-w-0">
            <div class="font-semibold leading-tight truncate text-gray-800">
                {{ $terpilih->customer->name ?? $terpilih->display_name ?? $terpilih->contact_key }}
            </div>
            <div class="text-[11px] text-gray-500 truncate">
                {{ $terpilih->contact_key }}
                @if($terbuka)
                    &middot; jendela terbuka {{ $terpilih->windowHoursLeft() }} jam lagi
                @else
                    &middot; jendela tertutup
                @endif
            </div>
        </div>
        <div class="ml-auto shrink-0">
            @if($terpilih->customer_id)
                <a href="{{ url('/erp/master/customers/' . $terpilih->customer_id . '/edit') }}"
                   class="text-[11px] px-2 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">Pelanggan</a>
            @else
                <span class="text-[11px] px-2 py-1 rounded bg-slate-100 text-slate-600" title="Belum tertaut pelanggan mana pun di ERP">Lead</span>
            @endif
        </div>
    </div>

    {{-- Daftar pesan: SATU-SATUNYA bagian yang menggulir. Kepala tetap di atas,
         kotak ketik tetap di bawah — seperti aplikasi chat. --}}
    <div x-ref="gulir" class="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-2 bg-[#efeae2]">
            @include('erp.crm.inbox._bubbles')
            <div x-ref="tambahan"></div>
            @if($pesan->isEmpty())
                <p class="text-center text-gray-500 py-6" x-show="kosong">Belum ada pesan.</p>
            @endif
        </div>

        {{-- ---------------------------------------------------------- balasan --}}
    <div class="shrink-0 border-t border-gray-200 bg-white p-3"
         @kirim-balasan="kirim($event.detail.form)">
            <div x-show="galat" x-cloak x-text="galat"
                 class="mb-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700"></div>
            @if($terbuka)
                @if($dryRun)
                    <div class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Mode aman menyala — balasan dicatat di thread, tidak dikirim ke pelanggan.
                    </div>
                @endif
                {{-- Tempel tangkapan layar (Ctrl+V), seret berkas, atau pilih dari tombol.
                     Diskusi desain hidup dari gambar; memaksa admin menyimpan berkas dulu
                     lalu mencarinya lewat dialog adalah alasan orang kembali ke HP. --}}
                <form method="POST" action="{{ route('crm.inbox.balas', $terpilih->id) }}"
                      class="relative"
                      enctype="multipart/form-data"
                      @submit.prevent="$dispatch('kirim-balasan', { form: $el })"
                      x-data="komposerCrm()"
                      @sisip-snippet.window="sisip($event.detail.teks)"
                      @balasan-terkirim="bersihkan()"
                      @dragover.prevent="seret = true"
                      @dragleave.prevent="seret = false"
                      @drop.prevent="jatuhkan($event)">
                    @csrf

                    <input type="file" name="gambar[]" multiple class="hidden"
                           x-ref="berkas" @change="dariDialog()">

                    {{-- Pratinjau gambar di ATAS baris ketik, seperti WhatsApp:
                         yang mau dikirim terlihat lebih dulu, baru kalimatnya. --}}
                    <div x-show="daftar.length" x-cloak class="flex flex-wrap gap-2 mb-2">
                        <template x-for="(g, i) in daftar" :key="g.key">
                            <div class="relative">
                                <img :src="g.url" class="h-16 w-16 object-cover rounded border">
                                <button type="button" @click="buang(i)"
                                        class="absolute -top-1.5 -right-1.5 bg-white border rounded-full w-5 h-5 text-xs leading-none text-gray-600 hover:text-red-600"
                                        title="Buang gambar ini">&times;</button>
                            </div>
                        </template>
                    </div>

                    {{-- Satu baris: lampiran · kotak ketik · kirim.
                         Kotaknya SETINGGI SATU BARIS saat kosong dan tumbuh sendiri
                         sampai maksimal 6 baris — kotak tiga baris yang selalu
                         menganga memakan tinggi thread untuk ruang yang 90% waktu
                         tidak terpakai. --}}
                    <div class="relative flex items-end gap-2">
                        {{-- Klip = pintu SEMUA lampiran, bukan cuma gambar. Produk ikut
                             di sini karena bagi admin keduanya satu gerakan yang sama:
                             "sisipkan sesuatu ke pesan ini". --}}
                        <div class="relative shrink-0" @click.outside="menu = false">
                            <button type="button" @click="menu = !menu" title="Lampirkan"
                                    class="w-10 h-10 flex items-center justify-center rounded-full border border-gray-300 text-gray-500 hover:bg-gray-50">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M18.4 12.6 12 19a4.5 4.5 0 1 1-6.4-6.4l7.1-7.1a3 3 0 1 1 4.2 4.2l-7.1 7.1a1.5 1.5 0 1 1-2.1-2.1l6.4-6.4"/>
                                </svg>
                            </button>

                            <div x-show="menu" x-cloak
                                 class="absolute bottom-12 left-0 z-20 w-44 bg-white border border-gray-200 rounded-lg shadow-lg py-1 text-sm">
                                <button type="button" @click="menu = false; $refs.berkas.click()"
                                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Gambar &amp; Berkas</button>
                                <button type="button" @click="menu = false; $dispatch('buka-produk')"
                                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Produk</button>
                            </div>
                        </div>

                        <textarea name="teks" rows="1" maxlength="4000"
                                  x-ref="teks"
                                  class="flex-1 border border-gray-300 rounded-2xl px-4 py-2.5 text-sm resize-none overflow-y-auto leading-6 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                                  :class="seret ? 'border-emerald-400 bg-emerald-50' : ''"
                                  @paste="tempel($event)"
                                  @keydown="enterKirim($event)"
                                  @input="tumbuh()"
                                  placeholder="Tulis balasan…">{{ old('teks') }}</textarea>

                        <button :disabled="sibuk" :class="sibuk && 'opacity-60 cursor-not-allowed'"
                                title="Kirim (Enter)"
                                class="shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-emerald-600 hover:bg-emerald-700 text-white">
                            <svg x-show="!sibuk" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/>
                            </svg>
                            <svg x-show="sibuk" x-cloak class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z"/>
                            </svg>
                        </button>

                        <div x-show="seret" x-cloak
                             class="absolute inset-0 flex items-center justify-center rounded-2xl border-2 border-dashed border-emerald-400 bg-emerald-50/90 text-sm text-emerald-700 pointer-events-none">
                            Lepas untuk melampirkan
                        </div>
                    </div>

                    <div class="mt-1.5 text-[11px] text-gray-400">
                        Enter kirim &middot; Shift+Enter baris baru &middot; Ctrl+V tempel gambar &middot; seret berkas ke sini
                    </div>

                    {{-- Pemilih produk: cari nama atau SKU, lalu langsung kirim.
                         Yang dikirim cuma TAUTANNYA — gambar, judul, dan ringkasan
                         dirangkai WhatsApp sendiri dari halaman etalase, jadi tak ada
                         media yang perlu diunggah. --}}
                    <div x-data="pilihProduk()" @buka-produk.window="buka()" x-show="tampil" x-cloak
                         class="absolute inset-x-0 bottom-0 z-30 p-3">
                        <div class="bg-white border border-gray-200 rounded-lg shadow-xl max-h-80 flex flex-col"
                             @click.outside="tampil = false">
                            <div class="shrink-0 flex items-center gap-2 p-2 border-b border-gray-200">
                                <input type="text" x-model="kata" x-ref="cari" @input.debounce.400ms="muat()"
                                       placeholder="Cari nama produk atau SKU…"
                                       class="flex-1 border rounded px-2 py-1.5 text-sm">
                                <button type="button" @click="tampil = false"
                                        class="px-2 text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
                            </div>

                            <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-gray-100">
                                <template x-if="sibuk">
                                    <p class="px-3 py-3 text-xs text-gray-400">Mencari…</p>
                                </template>

                                <template x-for="p in hasil" :key="p.url">
                                    <div class="flex items-center gap-2 px-3 py-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium truncate" x-text="p.nama"></div>
                                            <div class="text-[11px] text-gray-500 truncate">
                                                <span x-show="p.sku" class="font-mono" x-text="p.sku"></span>
                                                <span x-show="p.sku && p.ringkas"> &middot; </span>
                                                <span x-text="p.ringkas"></span>
                                            </div>
                                        </div>
                                        <button type="button" @click="kirimProduk(p)"
                                                class="shrink-0 bg-emerald-600 hover:bg-emerald-700 text-white text-xs px-3 py-1.5 rounded">
                                            Kirim
                                        </button>
                                    </div>
                                </template>

                                <template x-if="!sibuk && hasil.length === 0">
                                    <p class="px-3 py-3 text-xs text-gray-500"
                                       x-text="kata ? 'Tidak ada produk yang cocok.' : 'Belum ada produk terbit.'"></p>
                                </template>
                            </div>
                        </div>
                    </div>
                </form>

                <script>
                    /*
                     * Thread hidup: mengirim tanpa memuat ulang halaman, dan
                     * menarik pesan masuk secara berkala.
                     *
                     * Polling — bukan websocket — karena ERP ini tidak punya
                     * server realtime, volumenya beberapa chat sehari, dan satu
                     * permintaan ringan tiap beberapa detik jauh lebih murah
                     * daripada memasang infrastruktur baru yang harus dijaga.
                     */
                    function threadCrm(percakapanId, terakhirId) {
                        return {
                            terakhir: terakhirId,
                            kosong: terakhirId === 0,
                            sibuk: false,
                            galat: '',

                            init() {
                                this.keBawah(true);
                                // Jeda 8 detik: cukup terasa "langsung" untuk chat
                                // manusia, cukup jarang untuk tidak membebani.
                                this.jam = setInterval(() => this.tarik(), 8000);
                                // Berhenti menarik saat tab disembunyikan — laptop
                                // yang ditinggal semalam tak perlu ribuan permintaan.
                                document.addEventListener('visibilitychange', () => {
                                    if (!document.hidden) this.tarik();
                                });
                            },

                            destroy() { clearInterval(this.jam); },

                            async tarik() {
                                if (document.hidden || this.sibuk) return;

                                try {
                                    const url = '/erp/crm/' + percakapanId + '/pesan-baru?after=' + this.terakhir;
                                    const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                                    if (!r.ok) return;

                                    const d = await r.json();
                                    if (d.html) this.tempelHtml(d.html, d.last_id);
                                } catch (e) { /* jaringan putus sesaat: coba lagi siklus berikutnya */ }
                            },

                            tempelHtml(html, lastId) {
                                // Ditempel hanya kalau ADA isinya; menempel string
                                // kosong tetap memicu gulir dan terasa berkedip.
                                const dekatBawah = this.diBawah();
                                this.$refs.tambahan.insertAdjacentHTML('beforebegin', html);
                                this.terakhir = lastId;
                                this.kosong = false;
                                if (dekatBawah) this.keBawah();
                            },

                            diBawah() {
                                const el = this.$refs.gulir;
                                // Jangan menyeret admin yang sedang membaca ke atas.
                                return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
                            },

                            keBawah(langsung = false) {
                                this.$nextTick(() => {
                                    const el = this.$refs.gulir;
                                    el.scrollTo({ top: el.scrollHeight, behavior: langsung ? 'auto' : 'smooth' });
                                });
                            },

                            async kirim(form) {
                                if (this.sibuk) return;
                                this.sibuk = true;
                                this.galat = '';

                                const data = new FormData(form);
                                data.append('after', this.terakhir);

                                try {
                                    const r = await fetch(form.action, {
                                        method: 'POST',
                                        body: data,
                                        headers: {
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                                        },
                                    });
                                    const d = await r.json().catch(() => ({}));

                                    if (!r.ok || !d.success) {
                                        /*
                                         * Validasi Laravel (422) mengirim 'errors' per
                                         * field; 'message'-nya cuma "Data yang Anda
                                         * masukkan tidak valid" — tidak memberi tahu
                                         * apa pun. Yang ditampilkan harus alasan
                                         * sebenarnya, kalau tidak admin buntu.
                                         */
                                        const rinci = d.errors ? Object.values(d.errors).flat().join(' ') : '';
                                        this.galat = d.error || rinci || d.message || 'Gagal mengirim. Coba lagi.';
                                        return;
                                    }

                                    if (d.html) this.tempelHtml(d.html, d.last_id);
                                    form.reset();
                                    form.dispatchEvent(new CustomEvent('balasan-terkirim'));
                                    this.keBawah();
                                } catch (e) {
                                    this.galat = 'Jaringan bermasalah — pesan belum terkirim.';
                                } finally {
                                    this.sibuk = false;
                                }
                            },
                        };
                    }

                    function pilihProduk() {
                        return {
                            tampil: false, kata: '', hasil: [], sibuk: false,

                            buka() {
                                this.tampil = true;
                                this.$nextTick(() => this.$refs.cari.focus());
                                if (this.hasil.length === 0) this.muat();
                            },

                            async muat() {
                                this.sibuk = true;
                                try {
                                    const r = await fetch('{{ route('crm.produk.cari') }}?q=' + encodeURIComponent(this.kata),
                                                          { headers: { 'Accept': 'application/json' } });
                                    const d = await r.json();
                                    this.hasil = d.produk || [];
                                } catch (e) {
                                    this.hasil = [];
                                } finally {
                                    this.sibuk = false;
                                }
                            },

                            /*
                             * Tautannya ditaruh ke kotak ketik lalu form-nya dikirim —
                             * bukan jalur kirim tersendiri. Dengan begitu penjaga jendela
                             * 24 jam, saklar mode aman, dan penambahan gelembung tanpa
                             * muat ulang semuanya tetap berlaku tanpa digandakan.
                             */
                            kirimProduk(p) {
                                const form = this.$el.closest('form');
                                const ta = form?.querySelector('textarea[name=teks]');
                                if (!form || !ta) return;

                                /*
                                 * NAMA produk ikut dikirim, bukan tautan telanjang.
                                 * Kartu pratinjau WhatsApp belum terbukti muncul lewat
                                 * API vendor (penanda preview_url diterima tapi tak
                                 * berefek), dan tautan tanpa keterangan memaksa
                                 * pelanggan mengklik dulu untuk tahu itu produk apa.
                                 */
                                const teks = p.nama + '\n' + p.url;
                                ta.value = ta.value.trim() ? ta.value.trim() + '\n' + teks : teks;
                                this.tampil = false;
                                form.requestSubmit();
                            },
                        };
                    }

                    function komposerCrm() {
                        return {
                            daftar: [],
                            seret: false,
                            nomor: 0,
                            menu: false,

                            /*
                             * Enter mengirim, Shift+Enter baris baru — kebiasaan
                             * dari WhatsApp.
                             *
                             * `isComposing` WAJIB dilewati: saat mengetik dengan
                             * IME (emoji picker, papan ketik prediktif), Enter
                             * dipakai untuk MEMILIH kandidat kata. Tanpa penjaga
                             * ini, kalimat setengah jadi ikut terkirim dan tak
                             * bisa ditarik kembali.
                             */
                            enterKirim(e) {
                                if (e.key !== 'Enter') return;

                                // Shift+Enter (dan kombinasi lain) = baris baru.
                                if (e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;

                                /*
                                 * `isComposing` saja yang dipercaya sebagai penanda
                                 * IME. Memeriksa keyCode 229 secara buta JUSTRU
                                 * mematikan fitur ini di papan ketik Android, yang
                                 * melaporkan 229 untuk SEMUA tombol — persis gejala
                                 * "Enter cuma menambah baris".
                                 */
                                if (e.isComposing) return;

                                e.preventDefault();

                                const form = e.target.closest('form');
                                if (form && (e.target.value.trim() || this.daftar.length)) {
                                    form.requestSubmit();
                                }
                            },

                            // Potongan teks DISISIPKAN, bukan menimpa: admin sering
                            // sudah mengetik separuh kalimat sebelum teringat ada
                            // potongan yang cocok.
                            /*
                             * Kotak ketik ikut tinggi isinya, maksimal 6 baris.
                             * Tingginya dinolkan dulu ('auto') sebelum diukur —
                             * tanpa itu kotak hanya bisa MEMBESAR dan tidak
                             * pernah mengecil lagi setelah teksnya dihapus.
                             */
                            tumbuh() {
                                const ta = this.$refs.teks;
                                if (!ta) return;

                                const gaya = getComputedStyle(ta);
                                const baris = parseFloat(gaya.lineHeight) || 24;
                                const sisi = parseFloat(gaya.paddingTop) + parseFloat(gaya.paddingBottom)
                                           + parseFloat(gaya.borderTopWidth) + parseFloat(gaya.borderBottomWidth);

                                ta.style.height = 'auto';
                                ta.style.height = Math.min(ta.scrollHeight, baris * 6 + sisi) + 'px';
                            },

                            sisip(teks) {
                                const ta = this.$refs.teks;
                                if (!ta) return;
                                ta.value = ta.value.trim()
                                    ? ta.value.replace(/\s*$/, '') + '\n' + teks
                                    : teks;
                                ta.focus();
                                ta.setSelectionRange(ta.value.length, ta.value.length);
                                this.tumbuh();
                            },

                            // Setelah terkirim, pratinjau gambar ikut dibuang —
                            // form.reset() tidak menyentuh daftar di memori.
                            bersihkan() {
                                this.daftar.forEach(g => URL.revokeObjectURL(g.url));
                                this.daftar = [];
                                this.sinkron();
                                this.tumbuh();
                            },

                            tempel(e) {
                                const berkas = [...(e.clipboardData?.files || [])];
                                if (berkas.length) { e.preventDefault(); this.tambah(berkas); }
                            },

                            jatuhkan(e) {
                                this.seret = false;
                                this.tambah([...(e.dataTransfer?.files || [])]);
                            },

                            dariDialog() {
                                // Isi input dibaca lalu disusun ulang lewat jalur yang sama,
                                // supaya gambar yang dipilih lewat dialog bisa dibuang satu-satu
                                // persis seperti yang ditempel.
                                this.tambah([...this.$refs.berkas.files], true);
                            },

                            tambah(berkas, dariDialog = false) {
                                const gambar = berkas.filter(f => f.type.startsWith('image/'));
                                if (!gambar.length) { if (dariDialog) this.sinkron(); return; }

                                if (this.daftar.length + gambar.length > 5) {
                                    alert('Maksimal 5 gambar sekali kirim.');
                                    return this.sinkron();
                                }

                                for (const f of gambar) {
                                    if (f.size > 5 * 1024 * 1024) {
                                        alert(`"${f.name}" lebih dari 5 MB — batas WhatsApp.`);
                                        continue;
                                    }
                                    this.daftar.push({ key: ++this.nomor, file: f, url: URL.createObjectURL(f) });
                                }
                                this.sinkron();
                            },

                            buang(i) {
                                URL.revokeObjectURL(this.daftar[i].url);
                                this.daftar.splice(i, 1);
                                this.sinkron();
                            },

                            // Satu-satunya cara sah mengisi <input type=file> dari skrip.
                            sinkron() {
                                const dt = new DataTransfer();
                                this.daftar.forEach(g => dt.items.add(g.file));
                                this.$refs.berkas.files = dt.files;
                            },
                        };
                    }
                </script>
            @else
                {{-- Kotak ketik sengaja TIDAK ditampilkan saat jendela tertutup: kalau
                     ditampilkan, admin mengetik panjang lalu ditolak, dan langsung
                     kembali membalas dari HP. --}}
                <div class="rounded border border-gray-300 bg-gray-50 px-3 py-3 text-sm text-gray-700">
                    <b>Jendela 24 jam tertutup.</b> Pesan bebas tidak bisa dikirim; hanya template berbayar
                    yang boleh keluar. Cara termurah membukanya kembali: pelanggan membalas lebih dulu.
                </div>
            @endif
    </div>
</div>
