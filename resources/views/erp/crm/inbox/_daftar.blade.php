{{-- Kolom KIRI: antrean, filter, dan daftar percakapan.
     Bentuknya daftar (bukan tabel) karena lebarnya sempit — tabel di kolom
     sesempit ini memaksa gulir mendatar dan tak ada yang mau memakainya. --}}
@php
    $akuId       = auth()->id();
    $pemilikKini = request('pemilik');
    $milikSaya   = $pemilikKini === (string) $akuId;
    $semuaOrang  = $pemilikKini === 'semua';
    $agenLain    = $pemilikKini && ! $milikSaya && ! $semuaOrang;   // id agen lain / 'belum'
    $tabAktif    = 'px-2 py-1 rounded border border-emerald-600 bg-emerald-600 text-white';
    $tabDiam     = 'px-2 py-1 rounded border border-gray-300 bg-white hover:bg-gray-50';

    /* Aksi titik-tiga menuju URL yang MEMBAWA penyaring saat ini: sebagian aksi
       melempar balik ke daftar, dan tanpa kueri ini filter yang sedang dipakai
       ikut hilang. Dua '%s' diisi di sisi Alpine (id percakapan, lalu aksinya). */
    /* Penyaring yang sedang aktif, TANPA parameter milik penyegar otomatis.
       `sidik`/`terpilih`/`aplikasi` cuma dipakai endpoint daftar-segar; ikut
       terbawa ke tautan layar, ia membuat alamat yang dibagikan orang
       mengandung sidik jari data yang sudah basi sejak detik berikutnya. */
    $kueriBersih = collect(request()->query())
        ->except(['sidik', 'terpilih', 'aplikasi', 'page'])
        ->reject(fn ($v) => $v === null || $v === '');

    $kueriKini = $kueriBersih->isEmpty() ? '' : http_build_query($kueriBersih->all());
    $basisAksi = route('crm.inbox.index') . '/%s/%s' . ($kueriKini ? '?' . $kueriKini : '');

    /* ALAMAT LAYAR tempat chip penyaring bermuara.
     *
     * WAJIB diserahkan dari luar, TIDAK BOLEH dari request()->fullUrlWithQuery().
     * Kolom ini dirender ulang oleh penyegar otomatis di /erp/crm/daftar-segar,
     * yang mengembalikan JSON — dan di sana "request saat ini" adalah endpoint
     * itu. Chipnya jadi menunjuk ke JSON, dan begitu daftar menyegarkan diri
     * sekali saja, SETIAP tautan di kolom ini membuka halaman teks mentah.
     * Gejalanya menipu karena muncul belakangan: saat halaman baru dimuat
     * semuanya benar.
     */
    $basisFilter = $basisFilter ?? url()->current();

    /* Satu perangkai untuk semua chip: ganti sebagian parameter, buang yang
       kosong, sisanya dipertahankan. Ditulis sekali supaya chip berikutnya
       tidak lahir dengan lagi-lagi memanggil fullUrlWithQuery(). */
    $tautanSaring = function (array $ganti) use ($basisFilter, $kueriBersih) {
        $q = $kueriBersih->merge($ganti)->reject(fn ($v) => $v === null || $v === '')->all();

        return $basisFilter . ($q ? '?' . http_build_query($q) : '');
    };

    /* Tautan tiap baris MEMBAWA keadaan daftar (penyaring + berapa banyak yang
       sudah dimuat). Tanpa ini, membuka chat yang ditemukan setelah menggulir
       jauh akan mengembalikan daftar ke 20 baris teratas — tempat chat itu
       justru tidak ada, dan orangnya harus menggulir ulang dari awal. */
    $kueriBaris = collect(request()->query())
        ->except(['page', 'sidik', 'terpilih'])
        ->merge(['muat' => $muat])
        ->reject(fn ($v) => $v === null || $v === '')
        ->all();

    /* Dipakai dua aplikasi: kolom kiri ERP desktop dan layar daftar chat di PWA
       CRM (`/cs`). Yang berbeda cuma KE MANA satu baris membawa — endpoint,
       penyaring, dan aksi titik-tiganya sama persis, jadi daftarnya tidak boleh
       ditulis dua kali. Aksi POST tetap menuju `/erp/crm/*`: semuanya menutup
       dengan back(), yang mengembalikan orang ke layar asalnya sendiri. */
    $rutaChat   = $rutaChat   ?? 'crm.inbox.show';
    /* Penggulir otomatis hanya dipasang layar yang ikut memuat skripnya
       (workspace ERP). PWA /cs memakai partial yang sama tanpa skrip itu —
       di sana "Muat lagi" tetap jalan karena ia tautan sungguhan, bukan
       tombol yang menunggu JavaScript. */
    $tumbuhOtomatis = $tumbuhOtomatis ?? false;
    $gayaWadah  = $gayaWadah  ?? 'bg-white border border-gray-200 rounded-lg';
@endphp

<div class="flex flex-col h-full min-h-0 overflow-hidden {{ $gayaWadah }}"
     x-data="menuChatDaftar(@js($basisAksi), @js($terpilih?->id))">

    {{-- Kepala kolom: TIGA baris, tidak lebih.

         Urutannya menirukan cara pertanyaan datang: "chat siapa" (baris 1),
         "chat yang mana" (baris 2, pencarian), lalu "yang bagaimana" (baris 3,
         label). Sebelumnya kepala ini enam baris dan memakan sepertiga tinggi
         layar — di kolom chat, tinggi yang dimakan penyaring adalah percakapan
         yang tidak terlihat. --}}
    <div class="shrink-0 px-2 pt-2 pb-2 border-b border-gray-200 bg-gray-50 space-y-1.5">

    {{-- ---------------------------------------------------- 1. chat siapa --}}
    <div class="grid grid-cols-3 gap-1 text-xs">
        <a href="{{ $tautanSaring(['pemilik' => 'semua']) }}"
           class="text-center truncate {{ $semuaOrang ? $tabAktif : $tabDiam }}"
           title="Semua chat, milik siapa pun">Semua</a>

        <a href="{{ $tautanSaring(['pemilik' => $akuId]) }}"
           class="text-center truncate {{ $milikSaya || $dibatasiKeSaya ? $tabAktif : $tabDiam }}"
           title="Chat yang saya pegang">Milik Saya</a>

        @if($lihatSemua ?? false)
            {{-- Dropdown agen lain hanya untuk super admin: bagi agen biasa,
                 daftar nama rekan tidak menambah apa pun selain kebingungan —
                 yang ia butuh cuma miliknya dan yang belum dipegang siapa pun. --}}
            <form method="GET" class="contents">
                <input type="hidden" name="antrean" value="{{ request('antrean') }}">
                <input type="hidden" name="belum_dibaca" value="{{ request('belum_dibaca') }}">
                <input type="hidden" name="status" value="{{ request('status') }}">
                <input type="hidden" name="search" value="{{ request('search') }}">
                <select name="pemilik" onchange="this.form.submit()"
                        class="border rounded px-1 py-1 text-xs w-full {{ $agenLain ? 'border-emerald-600 text-emerald-700 font-medium' : 'border-gray-300' }}">
                    <option value="">Agen lain…</option>
                    <option value="belum" @selected($pemilikKini === 'belum')>Belum dioper ({{ $belumDioper }})</option>
                    @foreach($pemilikOpsi as $u)
                        @continue($u->id === $akuId)
                        <option value="{{ $u->id }}" @selected($pemilikKini === (string) $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </form>
        @else
            <a href="{{ $tautanSaring(['pemilik' => 'belum']) }}"
               class="text-center truncate {{ $pemilikKini === 'belum' ? $tabAktif : $tabDiam }}"
               title="Chat yang belum dipegang siapa pun">Belum dioper {{ $belumDioper }}</a>
        @endif
    </div>

    {{-- ------------------------------------------------------ 2. pencarian --}}
    <form method="GET" class="flex gap-1">
        <input type="hidden" name="antrean" value="{{ request('antrean') }}">
        <input type="hidden" name="belum_dibaca" value="{{ request('belum_dibaca') }}">
        <input type="hidden" name="status" value="{{ request('status') }}">
        <input type="hidden" name="pemilik" value="{{ request('pemilik') }}">
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Cari nama, nomor, atau nomor pesanan…"
               class="border rounded px-2 py-1.5 text-sm w-full">
        @if(request('search'))
            <a href="{{ $tautanSaring(['search' => null]) }}"
               class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100"
               title="Hapus pencarian">✕</a>
        @endif
        {{-- Popup, bukan pindah halaman: memulai chat itu sisipan di tengah
             kerja, dan meninggalkan layar berarti membuang thread yang sedang
             dibaca beserta filter kolom ini. --}}
        <button type="button" @click="$dispatch('mulai-chat', {})"
                class="shrink-0 bg-emerald-600 hover:bg-emerald-700 text-white rounded px-2.5 py-1.5 text-sm font-medium leading-5"
                title="Mulai chat baru">＋</button>

        {{-- Ekspor mengikuti SARINGAN YANG SEDANG AKTIF, bukan seluruh chat:
             yang dipakai menyusun pengetahuan agen adalah kumpulan yang sudah
             dipilih orang (satu label, satu pemilik, satu kata kunci), dan
             mengunduh semuanya cuma memindahkan pekerjaan memilah ke luar. --}}
        <a href="{{ route('crm.inbox.ekspor') . ($kueriKini ? '?' . $kueriKini : '') }}"
           class="shrink-0 border border-gray-300 rounded px-2 py-1.5 text-xs text-gray-500 hover:bg-gray-100 leading-5"
           title="Unduh chat sesuai saringan ini sebagai teks — bahan menyusun pengetahuan agen">⭳</a>
    </form>

    {{-- ---------------------------------------------------------- 3. label --}}
    @php
        $antreanAktif     = request('antrean');
        $belumDibacaAktif = request()->boolean('belum_dibaca');
        $semuaAktif       = ! $antreanAktif && ! $belumDibacaAktif;
        $chip             = 'px-1.5 py-0.5 rounded border text-[11px] leading-4';
    @endphp
    <div class="flex flex-wrap gap-1">
        <a href="{{ $tautanSaring(['antrean' => null, 'belum_dibaca' => null]) }}"
           class="{{ $chip }} {{ $semuaAktif ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
            Semua <span class="{{ $semuaAktif ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlahSemua }}</span>
        </a>

        {{-- "Belum dibaca" berdiri di sebelah "Semua", bukan di tengah label:
             ini bukan keadaan chat, melainkan pekerjaan yang belum disentuh. --}}
        <a href="{{ $tautanSaring(['belum_dibaca' => 1, 'antrean' => null]) }}"
           class="{{ $chip }} {{ $belumDibacaAktif ? 'border-red-600 bg-red-600 text-white' : ($jumlahBelumDibaca > 0 ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' : 'border-gray-300 bg-white hover:bg-gray-50') }}">
            Belum dibaca <span class="{{ $belumDibacaAktif ? 'text-red-100' : '' }}">{{ $jumlahBelumDibaca }}</span>
        </a>

        @foreach($labelOpsi as $l)
            <a href="{{ $tautanSaring(['antrean' => $l->kode, 'belum_dibaca' => null]) }}"
               class="{{ $chip }} {{ $antreanAktif === $l->kode ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
                {{ $l->nama }}
                <span class="{{ $antreanAktif === $l->kode ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlah[$l->kode] ?? 0 }}</span>
            </a>
        @endforeach

        {{-- Arsip jadi chip, bukan dropdown sendiri: ia dipakai sesekali dan
             tidak pantas menempati satu baris penuh selamanya. --}}
        @php $diArsip = request('status') === 'arsip'; @endphp
        <a href="{{ $tautanSaring(['status' => $diArsip ? null : 'arsip']) }}"
           class="{{ $chip }} {{ $diArsip ? 'border-gray-700 bg-gray-700 text-white' : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50' }}">
            Arsip
        </a>
    </div>

    </div>

    <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-gray-100">
        @forelse($percakapan as $p)
            @php
                $aktif   = $terpilih && $terpilih->id === $p->id;
                $namaTampil = $p->customer->name ?? $p->display_name ?? $p->contact_key;

                /* Belum dibaca ditandai di EMPAT tempat sekaligus — nama, cuplikan,
                   jam, dan lencana — meniru cara aplikasi pesan menandainya.
                   Sebelumnya cuma lencana kecil pucat di pojok, dan di daftar
                   sepanjang 500 baris satu titik sekecil itu hilang begitu saja:
                   yang dicari mata saat menggulir cepat adalah baris yang
                   BERBEDA, bukan angka yang harus ditemukan dulu. */
                $belum = (int) $p->unread_count > 0;

                /* Satu berkas data untuk SEMUA pintu masuk aksi cepat di baris ini
                   (titik tiga, chip label, chip pemilik). Ditulis sekali karena
                   tiga salinan yang hampir sama pasti menyimpang, dan yang
                   menyimpang diam-diam adalah popup yang terbuka dengan label
                   atau pemilik terpilih yang keliru. */
                $dataChat = [
                    'id' => $p->id, 'nama' => $namaTampil, 'label' => $p->queue_state, 'pemilik' => $p->owner_user_id,
                    'namaKontak' => $p->display_name, 'namaManual' => $p->name_source === \App\Modules\CRM\Models\CrmConversation::NAMA_MANUAL,
                    'pelanggan' => $p->customer?->name,
                ];
            @endphp
            {{-- Barisnya BUKAN <a> lagi: tombol titik tiga tak boleh bersarang di
                 dalam tautan. Tautannya dibentangkan jadi lapisan tak terlihat dan
                 isinya dibuat tembus-klik — tampilan sama, tapi tombolnya sah. --}}
            {{-- `data-baris-aktif` bukan hiasan: kelas warnanya dipakai juga oleh
                 chip filter yang sedang menyala, dan chip itu berdiri lebih dulu
                 di DOM — mencari baris aktif lewat kelas akan menemukan chip. --}}
            {{-- Baris aktif menang atas penanda belum-dibaca: chat yang sedang
                 dibuka memang sedang dibaca, dan dua latar berwarna bertumpuk
                 membuat keduanya sama-sama tak terbaca. --}}
            <div {{ $aktif ? 'data-baris-aktif' : '' }}
                 class="relative hover:bg-emerald-50
                        {{ $aktif ? 'bg-emerald-50 border-l-4 border-emerald-600'
                                  : ($belum ? 'bg-emerald-50/40 border-l-4 border-emerald-500' : '') }}">
                <a href="{{ route($rutaChat, array_merge([$p->id], $kueriBaris)) }}" class="absolute inset-0 z-0"
                   aria-label="Buka chat {{ $namaTampil }}"></a>

                <div class="relative z-10 pointer-events-none px-3 py-2">
                    <div class="flex items-start justify-between gap-2">
                        {{-- Nomor TIDAK diulang di bawah nama. Ia sudah jadi judul
                             baris saat kontaknya belum bernama, dan mengulangnya
                             pada kontak yang sudah bernama memakan satu baris utuh
                             untuk keterangan yang tak pernah dibaca — padahal baris
                             itu jauh lebih berguna untuk cuplikan chat terakhir,
                             satu-satunya petunjuk isi tanpa membuka threadnya. --}}
                        <div class="min-w-0">
                            <div class="flex items-center gap-1 min-w-0">
                                <span class="truncate {{ $belum ? 'font-bold text-gray-900' : 'font-medium' }}">{{ $namaTampil }}</span>
                                @unless($p->customer_id)
                                    <span class="shrink-0 px-1 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 uppercase"
                                          title="Belum tertaut pelanggan mana pun di ERP">Lead</span>
                                @endunless
                            </div>
                            <div class="text-xs truncate {{ $belum ? 'text-gray-800 font-semibold' : 'text-gray-500' }}">
                                @if($p->pesanTerakhir)
                                    {{-- Siapa yang bicara terakhir menentukan apakah
                                         bola ada di kita; tanpa penanda ini cuplikan
                                         balasan sendiri terbaca seperti pertanyaan
                                         pelanggan yang belum dijawab. --}}
                                    @if($p->pesanTerakhir->direction === \App\Modules\CRM\Models\CrmMessage::KELUAR)
                                        <span class="text-gray-400">Kami:</span>
                                    @endif
                                    {{ $p->pesanTerakhir->ringkas(60) }}
                                @else
                                    <span class="text-gray-400">Belum ada pesan</span>
                                @endif
                            </div>
                        </div>
                        {{-- Ruang kanan disisakan buat tombol titik tiga supaya jam
                             kirim tidak tertimpa olehnya di kolom sesempit ini. --}}
                        <div class="text-right shrink-0 pr-5">
                            <div class="text-[11px] {{ $belum ? 'text-emerald-700 font-semibold' : 'text-gray-400' }}">{{ $p->last_message_at?->diffForHumans() ?? '—' }}</div>
                            @if($belum)
                                {{-- Padat berisi, bukan pucat: lencana ini satu-satunya
                                     yang menyebut BERAPA, dan angkanya yang membedakan
                                     "satu sapaan" dari "sudah menunggu lima pesan". --}}
                                <span data-belum-dibaca
                                      class="inline-flex items-center justify-center mt-1 min-w-[20px] h-5 px-1.5 rounded-full
                                             text-[11px] font-bold bg-emerald-600 text-white shadow-sm">{{ $p->unread_count > 99 ? '99+' : $p->unread_count }}</span>
                            @endif
                        </div>
                    </div>
                    {{-- Dua chip ini BUKAN hiasan: keduanya tombol yang membuka
                         dropdown di tempat — label dan pemilik diganti langsung
                         dari barisnya, dua klik, tanpa meninggalkan daftar. Dua
                         aksi yang dipakai puluhan kali sehari tidak boleh
                         bersembunyi di balik titik tiga, apalagi ketika
                         targetnya sudah tercetak di baris itu juga.
                         `pointer-events-auto` dipasang per tombol, bukan pada
                         wadahnya: wadah yang bisa diklik akan memakan ruang
                         kosong di sebelah chip, padahal di situ orang menekan
                         untuk membuka chatnya. --}}
                    <div class="mt-1 flex flex-wrap items-center gap-1">
                        <button type="button" @click.prevent.stop="bukaChip($event, @js($dataChat), 'label')"
                                class="pointer-events-auto px-1.5 py-0.5 rounded text-[10px] hover:ring-1 hover:ring-gray-400
                                       {{ \App\Modules\CRM\Models\CrmLabel::kelas($p->queue_state) }}"
                                title="Ganti label">
                            {{ \App\Modules\CRM\Models\CrmLabel::nama($p->queue_state) }}
                        </button>
                        <button type="button" @click.prevent.stop="bukaChip($event, @js($dataChat), 'oper')"
                                class="pointer-events-auto px-1.5 py-0.5 rounded text-[10px] hover:ring-1 hover:ring-gray-400
                                       {{ $p->owner ? 'bg-gray-100 text-gray-600' : 'border border-dashed border-gray-300 text-gray-400' }}"
                                title="Oper chat ke agen lain">
                            {{ $p->owner->name ?? 'oper…' }}
                        </button>
                        {{-- Lencana jendela hanya sah untuk jalur BERBAYAR (Cloud
                             API). Di thread nomor utama lewat WAHA tak ada jendela
                             24 jam sama sekali, jadi menampilkannya di sana membuat
                             hampir setiap baris memakai lencana peringatan yang
                             tidak menghalangi apa pun — persis alarm palsu yang
                             sudah dibereskan di pita "pesan masuk tidak sampai". --}}
                        @if(! $p->tanpaJendela() && ! $p->windowIsOpen())
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-500"
                                  title="Hanya template berbayar yang bisa dikirim">jendela tutup</span>
                        @endif
                    </div>
                </div>

                <button type="button"
                        @click.prevent.stop="buka($event, @js($dataChat))"
                        class="absolute top-1.5 right-1 z-20 w-6 h-6 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-200 leading-none"
                        title="Aksi cepat">⋮</button>
            </div>
        @empty
            <p class="px-3 py-6 text-center text-sm text-gray-500">Belum ada percakapan.</p>
        @endforelse

        {{-- Penanda ujung daftar. Harus tinggal DI DALAM wadah yang menggulir:
             kaki di bawah sana selalu terlihat, jadi memakainya sebagai pemicu
             akan memuat semua halaman sekaligus begitu layar dibuka. --}}
        @if($adaLagi && $tumbuhOtomatis)
            <div data-daftar-ujung class="px-3 py-3 text-center text-[11px] text-gray-400">
                Memuat chat berikutnya…
            </div>
        @endif
    </div>

    {{-- Tombol manual tetap ada di samping pemuatan otomatis: penggulir otomatis
         mati kalau IntersectionObserver tak tersedia, dan daftar yang buntu tanpa
         jalan keluar jauh lebih buruk daripada satu tombol yang jarang dipakai. --}}
    <div class="shrink-0 px-3 py-2 border-t border-gray-200 bg-gray-50 text-xs text-gray-500
                flex items-center justify-between gap-2"
         data-daftar-kaki data-muat="{{ $muat }}" data-ada-lagi="{{ $adaLagi ? 1 : 0 }}">
        <span>{{ $percakapan->count() }} dari {{ $percakapan->total() }} chat</span>
        @if($adaLagi)
            <a href="{{ $tautanSaring(['muat' => $muat + 1]) }}"
               data-muat-lagi
               class="px-2 py-1 rounded border border-gray-300 bg-white hover:bg-gray-50">
                Muat lagi
            </a>
        @endif
    </div>

    {{-- --------------------------------------------------- menu & popup aksi --}}
    {{-- Ditumpangkan ke <body>: daftar percakapan menggulir di dalam wadah
         overflow-hidden, jadi menu yang tinggal di dalamnya akan terpotong. --}}
    <template x-teleport="body">
        <div>
            {{-- menu titik tiga --}}
            <div x-show="menu" x-cloak @click.outside="menu = false" @keydown.escape.window="menu = false"
                 :style="`top:${posisi.y}px; left:${posisi.x}px`"
                 class="fixed z-50 w-48 bg-white border border-gray-200 rounded-lg shadow-lg text-sm py-1">

                <form :action="aksi('belum-dibaca')" method="POST">
                    @csrf
                    <input type="hidden" name="terbuka" :value="terbukaId ?? ''">
                    <button class="w-full text-left px-3 py-2 hover:bg-gray-50">Tandai belum dibaca</button>
                </form>

                <button type="button" @click="popup = 'nama'; menu = false; $nextTick(() => $refs.inputNama?.select())"
                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Nama kontak…</button>

                {{-- Dua aksi ini membuka dropdown yang SAMA dengan chip di baris.
                     Posisinya sudah terisi saat menu ini dibuka, jadi dropdownnya
                     muncul di tempat yang sama — dan tidak ada dua layar berbeda
                     untuk satu pekerjaan yang sama. --}}
                <button type="button" @click.stop="chip = 'label'; menu = false"
                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Label…</button>

                <button type="button" @click.stop="chip = 'oper'; menu = false"
                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Oper chat…</button>
            </div>

            {{-- dropdown label / oper: BERLABUH ke chip yang diklik --}}
            {{-- Tiap pilihan adalah tombol kirim form-nya sendiri, bukan <select>
                 + tombol Simpan. Itu yang membedakannya dari popup lama: memilih
                 SUDAH berarti menyimpan, jadi ganti label selesai dalam dua klik
                 tanpa pernah meninggalkan daftar. --}}
            <div x-show="chip" x-cloak @click.outside="chip = null" @keydown.escape.window="chip = null"
                 :style="`top:${posisi.y}px; left:${posisi.x}px`"
                 class="fixed z-50 w-48 max-h-72 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg text-sm py-1">

                <div class="px-3 py-1 text-[11px] text-gray-400 truncate" x-text="chat.nama"></div>

                <template x-if="chip === 'label'">
                    <div>
                        @foreach(\App\Modules\CRM\Models\CrmLabel::terpakai() as $l)
                            <form :action="aksi('antrean')" method="POST">
                                @csrf
                                <input type="hidden" name="queue_state" value="{{ $l->kode }}">
                                <button class="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex items-center gap-2">
                                    <span class="w-3 text-emerald-600" x-text="chat.label === @js($l->kode) ? '✓' : ''"></span>
                                    <span class="px-1.5 py-0.5 rounded text-[10px] {{ \App\Modules\CRM\Models\CrmLabel::kelas($l->kode) }}">{{ $l->nama }}</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </template>

                <template x-if="chip === 'oper'">
                    <div>
                        <form :action="aksi('oper')" method="POST">
                            @csrf
                            <input type="hidden" name="owner_user_id" value="">
                            <button class="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex items-center gap-2 text-gray-500">
                                <span class="w-3 text-emerald-600" x-text="! chat.pemilik ? '✓' : ''"></span>
                                <span>— belum dioper —</span>
                            </button>
                        </form>
                        @foreach($pemilikOpsi as $u)
                            <form :action="aksi('oper')" method="POST">
                                @csrf
                                <input type="hidden" name="owner_user_id" value="{{ $u->id }}">
                                <button class="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex items-center gap-2">
                                    <span class="w-3 text-emerald-600" x-text="chat.pemilik === {{ $u->id }} ? '✓' : ''"></span>
                                    <span class="truncate">{{ $u->name }}</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </template>
            </div>

            {{-- popup ganti label / oper chat --}}
            <div x-show="popup" x-cloak class="fixed inset-0 z-50 lapis-layar flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" @click="popup = null"></div>

                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-sm p-4"
                     @keydown.escape.window="popup = null">
                    {{-- Modal ini tinggal melayani satu hal: nama kontak. Label dan
                         oper chat sudah pindah ke dropdown berlabuh di barisnya. --}}
                    <div class="text-sm font-semibold">Nama kontak</div>
                    <div class="text-xs text-gray-500 mb-3" x-text="chat.nama"></div>

                    {{-- Nama kontak: untuk mengenali lead yang belum beli. Tidak
                         membuat pelanggan di master — itu baru lahir saat ada
                         dokumen. Nama profil WhatsApp terisi sendiri; yang diketik
                         di sini menang dan tak pernah ditimpa otomatis. --}}
                    <div x-show="popup === 'nama'">
                        <form :action="aksi('nama')" method="POST">
                            @csrf
                            <input type="text" name="display_name" maxlength="100" x-ref="inputNama"
                                   :value="chat.namaKontak ?? ''" placeholder="mis. Bu Ferina — tanya kotak kepuasan"
                                   class="border rounded px-2 py-1.5 text-sm w-full">
                            <p class="mt-1.5 text-[11px] text-gray-500">
                                <span x-show="chat.namaKontak && ! chat.namaManual">Saat ini memakai nama profil WhatsApp.</span>
                                <span x-show="chat.namaManual">Nama ini diketik manual — tidak akan ditimpa nama WhatsApp.</span>
                                Kosongkan untuk kembali ke nama profil WhatsApp.
                            </p>
                            <p x-show="chat.pelanggan" class="mt-1 text-[11px] text-amber-700">
                                Chat ini sudah tertaut pelanggan <b x-text="chat.pelanggan"></b> — yang tampil tetap nama pelanggan itu.
                            </p>
                            <div class="mt-4 flex items-center justify-end gap-2">
                                <button type="submit" form="formAmbilNamaWa"
                                        class="mr-auto px-2 py-1 rounded border border-gray-300 text-xs text-gray-600 hover:bg-gray-50"
                                        title="Ganti dengan nama profil WhatsApp pelanggan">Ambil dari WhatsApp</button>
                                <button type="button" @click="popup = null"
                                        class="px-3 py-1.5 rounded border border-gray-300 text-sm hover:bg-gray-50">Batal</button>
                                <button class="px-3 py-1.5 rounded border border-emerald-600 text-emerald-700 text-sm hover:bg-emerald-50">Simpan</button>
                            </div>
                        </form>
                        <form id="formAmbilNamaWa" :action="aksi('nama')" method="POST" class="hidden">
                            @csrf
                            <input type="hidden" name="ambil_wa" value="1">
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </template>
</div>

<script>
function menuChatDaftar(basis, terbukaId) {
    return {
        basis, terbukaId,
        menu: false,
        popup: null,
        chip: null,          // 'label' | 'oper' — dropdown berlabuh di chip baris
        chat: { id: null, nama: '', label: null, pemilik: null, namaKontak: null, namaManual: false, pelanggan: null },
        posisi: { x: 0, y: 0 },

        buka(e, data) {
            this.chat = data;
            this.posisi = this.letak(e.currentTarget);
            this.menu = true;
            this.chip = null;
        },

        /* Dropdown chip memakai PENEMPAT yang sama dengan menu titik tiga, dan
           itu disengaja: dua rumus posisi untuk dua menu seukuran sama pasti
           berbeda nasibnya di baris terbawah daftar — yang satu terbalik ke
           atas, yang satu tenggelam di bawah layar. */
        bukaChip(e, data, jenis) {
            this.chat = data;
            this.posisi = this.letak(e.currentTarget);
            this.menu = false;
            this.chip = jenis;
        },

        /* Dirapatkan ke tepi kanan pemicunya, dan dibalik ke atas kalau sisa
           ruang di bawah tak cukup — baris terbawah daftar paling sering. */
        letak(el) {
            const r = el.getBoundingClientRect();

            return {
                x: Math.max(8, Math.min(r.right - 192, window.innerWidth - 200)),
                y: r.bottom + 180 > window.innerHeight ? Math.max(8, r.top - 172) : r.bottom + 4,
            };
        },

        aksi(sufiks) {
            return this.basis.replace('%s', this.chat.id ?? '').replace('%s', sufiks);
        },
    };
}
</script>
