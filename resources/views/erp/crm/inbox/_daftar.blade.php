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
    $kueriKini = request()->getQueryString();
    $basisAksi = route('crm.inbox.index') . '/%s/%s' . ($kueriKini ? '?' . $kueriKini : '');
@endphp

<div class="flex flex-col h-full min-h-0 bg-white border border-gray-200 rounded-lg overflow-hidden"
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
        <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'semua', 'page' => null]) }}"
           class="text-center truncate {{ $semuaOrang ? $tabAktif : $tabDiam }}"
           title="Semua chat, milik siapa pun">Semua</a>

        <a href="{{ request()->fullUrlWithQuery(['pemilik' => $akuId, 'page' => null]) }}"
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
            <a href="{{ request()->fullUrlWithQuery(['pemilik' => 'belum', 'page' => null]) }}"
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
            <a href="{{ request()->fullUrlWithQuery(['search' => null, 'page' => null]) }}"
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
        <a href="{{ request()->fullUrlWithQuery(['antrean' => null, 'belum_dibaca' => null, 'page' => null]) }}"
           class="{{ $chip }} {{ $semuaAktif ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
            Semua <span class="{{ $semuaAktif ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlahSemua }}</span>
        </a>

        {{-- "Belum dibaca" berdiri di sebelah "Semua", bukan di tengah label:
             ini bukan keadaan chat, melainkan pekerjaan yang belum disentuh. --}}
        <a href="{{ request()->fullUrlWithQuery(['belum_dibaca' => 1, 'antrean' => null, 'page' => null]) }}"
           class="{{ $chip }} {{ $belumDibacaAktif ? 'border-red-600 bg-red-600 text-white' : ($jumlahBelumDibaca > 0 ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' : 'border-gray-300 bg-white hover:bg-gray-50') }}">
            Belum dibaca <span class="{{ $belumDibacaAktif ? 'text-red-100' : '' }}">{{ $jumlahBelumDibaca }}</span>
        </a>

        @foreach($labelOpsi as $l)
            <a href="{{ request()->fullUrlWithQuery(['antrean' => $l->kode, 'belum_dibaca' => null, 'page' => null]) }}"
               class="{{ $chip }} {{ $antreanAktif === $l->kode ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-gray-300 bg-white hover:bg-gray-50' }}">
                {{ $l->nama }}
                <span class="{{ $antreanAktif === $l->kode ? 'text-emerald-100' : 'text-gray-500' }}">{{ $jumlah[$l->kode] ?? 0 }}</span>
            </a>
        @endforeach

        {{-- Arsip jadi chip, bukan dropdown sendiri: ia dipakai sesekali dan
             tidak pantas menempati satu baris penuh selamanya. --}}
        @php $diArsip = request('status') === 'arsip'; @endphp
        <a href="{{ request()->fullUrlWithQuery(['status' => $diArsip ? null : 'arsip', 'page' => null]) }}"
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
            @endphp
            {{-- Barisnya BUKAN <a> lagi: tombol titik tiga tak boleh bersarang di
                 dalam tautan. Tautannya dibentangkan jadi lapisan tak terlihat dan
                 isinya dibuat tembus-klik — tampilan sama, tapi tombolnya sah. --}}
            <div class="relative hover:bg-emerald-50 {{ $aktif ? 'bg-emerald-50 border-l-4 border-emerald-600' : '' }}">
                <a href="{{ route('crm.inbox.show', $p->id) }}" class="absolute inset-0 z-0"
                   aria-label="Buka chat {{ $namaTampil }}"></a>

                <div class="relative z-10 pointer-events-none px-3 py-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-medium truncate">{{ $namaTampil }}</div>
                            <div class="text-xs text-gray-500 truncate">
                                {{ $p->contact_key }}
                                @unless($p->customer_id)
                                    <span class="ml-1 px-1 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 uppercase"
                                          title="Belum tertaut pelanggan mana pun di ERP">Lead</span>
                                @endunless
                            </div>
                        </div>
                        {{-- Ruang kanan disisakan buat tombol titik tiga supaya jam
                             kirim tidak tertimpa olehnya di kolom sesempit ini. --}}
                        <div class="text-right shrink-0 pr-5">
                            <div class="text-[11px] text-gray-400">{{ $p->last_message_at?->diffForHumans() ?? '—' }}</div>
                            @if($p->unread_count > 0)
                                <span class="inline-block mt-1 px-1.5 rounded-full text-[10px] font-semibold bg-red-100 text-red-700">{{ $p->unread_count }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-1">
                        <span class="px-1.5 py-0.5 rounded text-[10px] {{ \App\Modules\CRM\Models\CrmLabel::kelas($p->queue_state) }}">
                            {{ \App\Modules\CRM\Models\CrmLabel::nama($p->queue_state) }}
                        </span>
                        @if($p->owner)
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-600">{{ $p->owner->name }}</span>
                        @endif
                        @unless($p->windowIsOpen())
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-500"
                                  title="Hanya template berbayar yang bisa dikirim">jendela tutup</span>
                        @endunless
                    </div>
                </div>

                <button type="button"
                        @click.prevent.stop="buka($event, @js(['id' => $p->id, 'nama' => $namaTampil, 'label' => $p->queue_state, 'pemilik' => $p->owner_user_id]))"
                        class="absolute top-1.5 right-1 z-20 w-6 h-6 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-200 leading-none"
                        title="Aksi cepat">⋮</button>
            </div>
        @empty
            <p class="px-3 py-6 text-center text-sm text-gray-500">Belum ada percakapan.</p>
        @endforelse
    </div>

    <div class="shrink-0 px-3 py-2 border-t border-gray-200 bg-gray-50 text-xs">{{ $percakapan->links() }}</div>

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

                <button type="button" @click="popup = 'label'; menu = false"
                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Label…</button>

                <button type="button" @click="popup = 'oper'; menu = false"
                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Oper chat…</button>
            </div>

            {{-- popup ganti label / oper chat --}}
            <div x-show="popup" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" @click="popup = null"></div>

                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-sm p-4"
                     @keydown.escape.window="popup = null">
                    <div class="text-sm font-semibold" x-text="popup === 'label' ? 'Ganti label' : 'Oper chat'"></div>
                    <div class="text-xs text-gray-500 mb-3" x-text="chat.nama"></div>

                    <form x-show="popup === 'label'" :action="aksi('antrean')" method="POST">
                        @csrf
                        <select name="queue_state" class="border rounded px-2 py-1.5 text-sm w-full">
                            @foreach(\App\Modules\CRM\Models\CrmLabel::terpakai() as $l)
                                <option value="{{ $l->kode }}" :selected="chat.label === @js($l->kode)">{{ $l->nama }}</option>
                            @endforeach
                        </select>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" @click="popup = null"
                                    class="px-3 py-1.5 rounded border border-gray-300 text-sm hover:bg-gray-50">Batal</button>
                            <button class="px-3 py-1.5 rounded border border-emerald-600 text-emerald-700 text-sm hover:bg-emerald-50">Ubah</button>
                        </div>
                    </form>

                    <form x-show="popup === 'oper'" :action="aksi('oper')" method="POST">
                        @csrf
                        <select name="owner_user_id" class="border rounded px-2 py-1.5 text-sm w-full">
                            <option value="" :selected="! chat.pemilik">— belum dioper —</option>
                            @foreach($pemilikOpsi as $u)
                                <option value="{{ $u->id }}" :selected="chat.pemilik === {{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" @click="popup = null"
                                    class="px-3 py-1.5 rounded border border-gray-300 text-sm hover:bg-gray-50">Batal</button>
                            <button class="px-3 py-1.5 rounded border border-emerald-600 text-emerald-700 text-sm hover:bg-emerald-50">Oper</button>
                        </div>
                    </form>
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
        chat: { id: null, nama: '', label: null, pemilik: null },
        posisi: { x: 0, y: 0 },

        buka(e, data) {
            this.chat = data;
            const r = e.currentTarget.getBoundingClientRect();
            // Menu dirapatkan ke tepi kanan tombol, dan dibalik ke atas kalau
            // sisa ruang di bawah tak cukup — baris terbawah daftar paling sering.
            this.posisi = {
                x: Math.max(8, Math.min(r.right - 192, window.innerWidth - 200)),
                y: r.bottom + 140 > window.innerHeight ? Math.max(8, r.top - 132) : r.bottom + 4,
            };
            this.menu = true;
        },

        aksi(sufiks) {
            return this.basis.replace('%s', this.chat.id ?? '').replace('%s', sufiks);
        },
    };
}
</script>
