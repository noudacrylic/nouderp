{{-- Kolom TENGAH: percakapan + kotak balasan. Dipisah jadi partial supaya
     kolom kiri & rail kanan tidak ikut disusun ulang tiap kali thread dibuka. --}}
@php
    $terbuka = $terpilih->windowIsOpen();
    // Ambangnya dibaca dari servicenya, bukan diketik ulang: angka yang
    // berbeda sedikit saja berarti tombolnya muncul di layar lalu ditolak
    // saat ditekan.
    $hampirTutup = $terpilih->windowHampirTutup(\App\Modules\CRM\Services\CrmReplyService::AMBANG_PANCINGAN_MENIT);

    /*
     * Potongan balasan untuk pintasan "/" di kotak ketik. Isiannya ({nama},
     * {nomor}, {pesanan}, {admin}) diganti DI SINI, sekali, bukan di skrip:
     * {pesanan} perlu membaca pesanan terakhir pelanggan, dan itu tidak
     * mungkin diketahui browser.
     */
    $potongan = $snippets->map(fn ($t) => [
        'id'    => $t->id,
        'judul' => $t->title,
        'grup'  => $t->category,
        'teks'  => $t->render($terpilih, auth()->user()?->name),
    ])->values();
@endphp

{{-- Keadaan centang dipilih CSS, bukan dengan membangun ulang HTML: memperbarui
     status jadi sekadar mengganti satu atribut, dan gelembung sementara di skrip
     bisa memakai markup yang sama persis. --}}
<style>
    .crm-centang       { display: inline-flex; align-items: center; }
    .crm-centang > svg { display: none; width: .95rem; height: .95rem; }
    .crm-centang[data-status="menunggu"] .ct-jam,
    .crm-centang[data-status="terkirim"] .ct-satu,
    .crm-centang[data-status="sampai"]   .ct-dua,
    .crm-centang[data-status="dibaca"]   .ct-dua { display: block; }
    /* Biru WhatsApp untuk "dibaca" — satu-satunya keadaan yang berwarna, supaya
       terbaca sekilas tanpa harus menghitung centang. */
    .crm-centang[data-status="dibaca"]   { color: #53bdeb; }
    .crm-centang[data-status="menunggu"] { opacity: .55; }
</style>

{{-- Cetakan yang diklon skrip untuk gelembung sementara. Satu markup, dua jalur. --}}
<template id="crm-centang-cetak">@include('erp.crm.inbox._centang', ['status' => 'menunggu'])</template>

{{-- ------------------------------------------------------------ percakapan --}}
{{-- Seluruh kolom percakapan jadi zona jatuh, bukan cuma kotak ketiknya.
     Kotak ketik itu strip setinggi 40px di dasar layar: menuntut berkas
     dilepas TEPAT di situ membuat fiturnya terasa tidak ada, dan orang kembali
     mengirim lampiran dari HP. Yang dilihat orang saat menyeret berkas adalah
     percakapannya, jadi ke situlah berkasnya diarahkan.

     Zonanya hanya dipasang saat jendela 24 jam terbuka — kalau tidak, berkas
     yang dilepas hilang tanpa jejak karena kotak ketiknya memang tidak ada. --}}
<div class="relative flex flex-col h-full min-h-0 bg-white border border-gray-200 rounded-lg overflow-hidden"
     x-data="threadCrm({{ $terpilih->id }}, {{ (int) ($pesan->max('id') ?? 0) }})"
     {{-- Foto produk dikirim dari panel Produk di rail kanan. Yang MENGIRIM
          tetap thread, bukan panelnya: hanya di sini id pesan terakhir diketahui,
          dan tanpa itu jawabannya membawa ulang seluruh riwayat sebagai
          gelembung dobel. --}}
     @kirim-foto-produk.window="kirimFotoProduk($event.detail)"
     @if($terbuka)
     @dragenter.prevent="mulaiSeret($event)"
     @dragover.prevent
     @dragleave.prevent="akhiriSeret()"
     @drop.prevent="jatuhBerkas($event)"
     @endif>

    @if($terbuka)
        {{-- pointer-events-none: tirainya cuma penanda, peristiwa jatuhnya tetap
             harus sampai ke wadah di bawahnya. --}}
        <div x-show="seret" x-cloak
             class="absolute inset-0 z-30 flex items-center justify-center rounded-lg
                    border-2 border-dashed border-emerald-400 bg-emerald-50/95 pointer-events-none">
            <div class="text-center px-6">
                <svg class="w-10 h-10 mx-auto text-emerald-500" fill="none" stroke="currentColor"
                     stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 16V4m0 0L8 8m4-4 4 4M4 16v2.5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V16"/>
                </svg>
                <p class="mt-2 font-semibold text-emerald-800">Lepas untuk melampirkan</p>
                <p class="mt-0.5 text-xs text-emerald-700">
                    Gambar, PDF, DXF, video, dokumen &middot; maksimal 5 berkas sekali kirim
                </p>
            </div>
        </div>
    @endif

    {{-- Kepala percakapan: siapa yang sedang dibalas, selalu terlihat walau
         thread digulir jauh ke atas. Tanpa ini admin yang membuka beberapa
         chat berturut-turut kehilangan jejak sedang bicara dengan siapa. --}}
    <div class="shrink-0 flex items-center gap-3 px-4 py-2.5 border-b border-gray-200 bg-white"
         x-data="{ menu: false, catatan: false }">
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
        <div class="ml-auto shrink-0 flex items-center gap-2">
            @if($terpilih->customer_id)
                <a href="{{ url('/erp/master/customers/' . $terpilih->customer_id . '/edit') }}"
                   class="text-[11px] px-2 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">Pelanggan</a>
            @else
                <span class="text-[11px] px-2 py-1 rounded bg-slate-100 text-slate-600" title="Belum tertaut pelanggan mana pun di ERP">Lead</span>
            @endif

            {{-- Bekas tab "Info". Yang tersisa di sana setelah label & pemilik
                 pindah ke kolom kiri cuma tiga hal yang jarang disentuh —
                 catatan, arsip, dan penarik lampiran tertunda. Satu kolom penuh
                 untuk tiga tombol itu terlalu mahal, jadi mereka masuk ke menu
                 titik tiga yang menempel pada percakapan yang sedang dibuka. --}}
            <div class="relative" @click.outside="menu = false" @keydown.escape.window="menu = false">
                <button type="button" @click="menu = !menu" title="Aksi percakapan"
                        class="w-7 h-7 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-100 leading-none">&vellip;</button>

                <div x-show="menu" x-cloak
                     class="absolute right-0 top-8 z-30 w-56 bg-white border border-gray-200 rounded-lg shadow-lg py-1 text-sm">

                    <button type="button" @click="menu = false; catatan = true"
                            class="w-full text-left px-3 py-2 hover:bg-gray-50">
                        Catatan internal
                        @if(filled($terpilih->notes))<span class="ml-1 text-[10px] text-emerald-700">&bull; ada</span>@endif
                    </button>

                    <form method="POST" action="{{ route('crm.inbox.arsip', $terpilih->id) }}">
                        @csrf
                        <button class="w-full text-left px-3 py-2 hover:bg-gray-50">
                            {{ $terpilih->status === 'aktif' ? 'Arsipkan' : 'Aktifkan lagi' }}
                        </button>
                    </form>

                    {{-- Penjadwal menarik lampiran tiap menit; tombol ini jaring
                         pengaman saat penjadwalnya mati (di lokal tak pernah
                         hidup). Hanya muncul kalau memang ada yang menggantung. --}}
                    @php $lampiranTertunda = \App\Modules\CRM\Models\CrmAttachment::belumTerunduh()->count(); @endphp
                    @if($lampiranTertunda > 0)
                        <form method="POST" action="{{ route('crm.lampiran.unduh') }}">
                            @csrf
                            <button class="w-full text-left px-3 py-2 text-amber-700 hover:bg-amber-50">
                                Unduh {{ $lampiranTertunda }} lampiran tertunda
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Catatan internal sebagai popup, bukan kolom yang selalu terbuka:
             isinya dibaca sekali di awal percakapan lalu ditinggalkan. --}}
        <div x-show="catatan" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             @keydown.escape.window="catatan = false">
            <div class="absolute inset-0 bg-black/40" @click="catatan = false"></div>
            <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-4">
                <div class="text-sm font-semibold">Catatan internal</div>
                <div class="text-xs text-gray-500 mb-3">{{ $terpilih->customer->name ?? $terpilih->display_name ?? $terpilih->contact_key }}</div>
                <form method="POST" action="{{ route('crm.inbox.catatan', $terpilih->id) }}">
                    @csrf
                    <textarea name="notes" rows="7" class="border rounded w-full px-3 py-2 text-sm"
                              placeholder="Spesifikasi, kesepakatan, hal yang perlu diingat…">{{ $terpilih->notes }}</textarea>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" @click="catatan = false"
                                class="px-3 py-1.5 rounded border border-gray-300 text-sm hover:bg-gray-50">Tutup</button>
                        <button class="px-3 py-1.5 rounded border border-emerald-600 text-emerald-700 text-sm hover:bg-emerald-50">Simpan Catatan</button>
                    </div>
                </form>
            </div>
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
                {{-- Peringatan dini. Jendela yang tinggal sebentar tidak
                     kelihatan dari kotak ketik yang bekerja normal — dan yang
                     paling sering menutupnya bukan pelanggan yang pergi,
                     melainkan akhir pekan yang datang di tengah pembahasan.
                     Diberikan bersama tombolnya, karena peringatan tanpa jalan
                     keluar cuma memindahkan kepanikan. --}}
                @if($hampirTutup)
                    <div class="mb-2 flex flex-wrap items-center gap-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <span>
                            Jendela 24 jam tinggal
                            <b>{{ $terpilih->windowMinutesLeft() < 60
                                ? $terpilih->windowMinutesLeft() . ' menit'
                                : $terpilih->windowHoursLeft() . ' jam' }}</b>.
                            Penjadwal memancing sendiri di rentang ini; tombol ini untuk mendahuluinya.
                            Selagi jendela belum tutup, pancingannya <b>gratis</b>.
                        </span>
                        @if($terpilih->pancingan_untuk_jendela_at?->equalTo($terpilih->window_expires_at))
                            <span class="text-[11px] text-amber-700">sudah dipancing untuk jendela ini</span>
                        @endif
                        <form method="POST" action="{{ route('crm.inbox.pancingan', $terpilih) }}" class="ml-auto">
                            @csrf
                            <button type="submit"
                                    class="px-2.5 py-1 rounded border border-amber-600 text-amber-800 hover:bg-amber-100">
                                Kirim pancingan
                            </button>
                        </form>
                    </div>
                @endif
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
                      x-data="komposerCrm(@js($potongan))"
                      {{-- Panel Produk memakai peristiwa yang sama, hanya dengan
                           penanda `kirim` — tautan produk memang tak perlu
                           disunting lagi sebelum berangkat. --}}
                      @sisip-teks.window="sisip($event.detail.teks); if ($event.detail.kirim) $el.requestSubmit()"
                      @balasan-terkirim="bersihkan()"
                      {{-- Berkas datang dari zona jatuh sekolom penuh di atas,
                           bukan dari form ini. Dilempar lewat peristiwa supaya
                           zona itu tak perlu tahu isi dalaman komposer. --}}
                      @berkas-jatuh.window="tambah($event.detail.berkas)">
                    @csrf

                    <input type="file" name="gambar[]" multiple class="hidden"
                           x-ref="berkas" @change="dariDialog()">

                    {{-- Strip kutipan: pesan yang sedang dibalas, tepat di atas kotak
                         ketik seperti WhatsApp. Nilainya ikut FormData lewat input
                         tersembunyi, jadi jalur kirim yang sudah ada tak perlu tahu
                         apa-apa soal kutipan.

                         Yang dikirim id VENDOR pesannya, bukan id baris kita —
                         itu yang dimengerti vendor pada 'reply_to_message_id'. --}}
                    <input type="hidden" name="reply_to" :value="kutipan?.pmid ?? ''">

                    <div x-show="kutipan" x-cloak
                         class="mb-2 flex items-stretch gap-2 rounded bg-gray-100 border border-gray-200 overflow-hidden">
                        <div class="w-1 shrink-0"
                             :class="kutipan?.masuk ? 'bg-sky-500' : 'bg-emerald-500'"></div>
                        <div class="min-w-0 flex-1 py-1.5">
                            <div class="text-[11px] font-semibold"
                                 :class="kutipan?.masuk ? 'text-sky-700' : 'text-emerald-700'"
                                 x-text="kutipan?.nama"></div>
                            <div class="text-[12px] text-gray-600 truncate" x-text="kutipan?.ringkas"></div>
                        </div>
                        <button type="button" @click="batalKutip()" title="Batalkan kutipan (Esc)"
                                class="px-3 text-gray-400 hover:text-gray-700 text-lg leading-none">&times;</button>
                    </div>

                    {{-- Pratinjau gambar di ATAS baris ketik, seperti WhatsApp:
                         yang mau dikirim terlihat lebih dulu, baru kalimatnya. --}}
                    {{-- Gambar tampil sebagai thumbnail, berkas lain sebagai keping
                         bernama. Berkas kerja (DXF, RLD, PDF) tak punya pratinjau, dan
                         memaksakan kotak kosong justru membuat admin ragu apakah
                         berkasnya benar-benar terlampir. --}}
                    <div x-show="daftar.length" x-cloak class="flex flex-wrap gap-2 mb-2">
                        <template x-for="(g, i) in daftar" :key="g.key">
                            <div class="relative">
                                <template x-if="g.url">
                                    <img :src="g.url" class="h-16 w-16 object-cover rounded border">
                                </template>
                                <template x-if="!g.url">
                                    <div class="h-16 min-w-[7rem] max-w-[11rem] px-2 rounded border bg-gray-50 flex flex-col justify-center">
                                        <div class="text-[11px] font-medium truncate" x-text="g.file.name"></div>
                                        <div class="text-[10px] text-gray-500" x-text="g.ukuran"></div>
                                    </div>
                                </template>
                                <button type="button" @click="buang(i)"
                                        class="absolute -top-1.5 -right-1.5 bg-white border rounded-full w-5 h-5 text-xs leading-none text-gray-600 hover:text-red-600"
                                        title="Buang lampiran ini">&times;</button>
                            </div>
                        </template>
                    </div>

                    {{-- Satu baris: lampiran · kotak ketik · kirim.
                         Kotaknya SETINGGI SATU BARIS saat kosong dan tumbuh sendiri
                         sampai maksimal 6 baris — kotak tiga baris yang selalu
                         menganga memakan tinggi thread untuk ruang yang 90% waktu
                         tidak terpakai. --}}
                    <div class="relative flex items-end gap-2">
                        {{-- Klip = pintu lampiran. Produk TIDAK lagi di sini: ia
                             pindah ke rail kanan, karena yang dibutuhkan admin
                             bukan cuma mengirim tautan melainkan membaca stok &
                             harganya sambil mengetik. --}}
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
                            </div>
                        </div>

                        {{-- Template = potongan balasan milik sendiri, GRATIS,
                             tapi hanya sah di dalam jendela 24 jam — karena itu
                             ia hidup di kotak ketik yang memang cuma muncul saat
                             jendelanya terbuka.

                             Dua pintu ke barang yang sama: tekan "/" di kotak
                             kosong (kebiasaan WhatsApp) atau tombol ini, untuk
                             yang tak tahu ada pintasannya. Isian {nama}, {nomor}
                             dst. sudah diganti di server, jadi yang disisipkan
                             sudah berupa kalimat jadi. --}}
                        <div class="relative shrink-0" @click.outside="tutupTemplate(false)">
                            <button type="button" @click="tplBuka ? tutupTemplate() : bukaTemplate()"
                                    title="Template balasan (tekan / di kotak kosong)"
                                    class="w-10 h-10 flex items-center justify-center rounded-full border text-gray-500 hover:bg-gray-50"
                                    :class="tplBuka ? 'border-emerald-500 text-emerald-600 bg-emerald-50' : 'border-gray-300'">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M8 10h8M8 14h5M21 12a8 8 0 0 1-8 8H7l-4 3v-4.6A8 8 0 1 1 21 12Z"/>
                                </svg>
                            </button>

                            <div x-show="tplBuka" x-cloak
                                 class="absolute bottom-12 left-0 z-30 w-[22rem] max-w-[80vw] bg-white border border-gray-200 rounded-lg shadow-xl text-sm overflow-hidden">

                                <div class="p-2 border-b border-gray-200 bg-gray-50">
                                    {{-- Bukan <input type=text> polos: Enter di
                                         dalam form akan mengirim balasan setengah
                                         jadi, jadi tiap tombol dijaga sendiri. --}}
                                    <input type="text" x-ref="tplCari" x-model="tplCari"
                                           @keydown="tplNavigasi($event)"
                                           placeholder="Cari template…"
                                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                </div>

                                <div class="max-h-72 overflow-y-auto">
                                    <template x-for="(t, i) in tplHasil" :key="t.id">
                                        <button type="button" @click="pakaiTemplate(t)" @mouseenter="tplPilih = i"
                                                class="w-full text-left px-3 py-2 border-b border-gray-100 last:border-0"
                                                :class="tplPilih === i ? 'bg-emerald-50' : 'hover:bg-gray-50'">
                                            <div class="flex items-baseline gap-2">
                                                <span class="font-medium truncate" x-text="t.judul"></span>
                                                <span x-show="t.grup" class="text-[10px] uppercase tracking-wide text-gray-400 shrink-0" x-text="t.grup"></span>
                                            </div>
                                            <div class="text-xs text-gray-500 overflow-hidden" x-text="t.teks"
                                                 style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical"></div>
                                        </button>
                                    </template>

                                    <p x-show="! tplHasil.length" class="px-3 py-4 text-center text-xs text-gray-500">
                                        <span x-text="tpl.length ? 'Tidak ada yang cocok.' : 'Belum ada template balasan.'"></span>
                                    </p>
                                </div>

                                <div class="px-3 py-1.5 border-t border-gray-200 bg-gray-50 flex items-center justify-between text-[11px] text-gray-500">
                                    <span>&uarr;&darr; pilih &middot; Enter sisipkan &middot; Esc tutup</span>
                                    <a href="{{ route('crm.template.index') }}" class="text-emerald-700 hover:underline">Kelola</a>
                                </div>
                            </div>
                        </div>

                        <textarea name="teks" rows="1" maxlength="4000"
                                  x-ref="teks"
                                  class="flex-1 border border-gray-300 rounded-2xl px-4 py-2.5 text-sm resize-none overflow-y-auto leading-6 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                                  @paste="tempel($event)"
                                  @keydown="enterKirim($event); pintasTemplate($event)"
                                  @input="tumbuh()"
                                  placeholder="Tulis balasan…">{{ old('teks') }}</textarea>

                        {{-- Tombolnya TIDAK lagi dikunci selama mengirim: penanda
                             tunggu sudah pindah ke gelembungnya, dan mengunci tombol
                             justru menahan balasan berikutnya yang sudah siap. --}}
                        <button title="Kirim (Enter)"
                                class="shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-emerald-600 hover:bg-emerald-700 text-white">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/>
                            </svg>
                        </button>

                    </div>

                    <div class="mt-1.5 text-[11px] text-gray-400">
                        Enter kirim &middot; Shift+Enter baris baru &middot; <b>/</b> template
                        &middot; Ctrl+V tempel gambar &middot; seret berkas ke mana saja di percakapan ini
                    </div>

                </form>

            @else
                {{-- Kotak ketik sengaja TIDAK ditampilkan saat jendela tertutup: kalau
                     ditampilkan, admin mengetik panjang lalu ditolak, dan langsung
                     kembali membalas dari HP. --}}
                <div class="rounded border border-gray-300 bg-gray-50 px-3 py-3 text-sm text-gray-700">
                    <b>Jendela 24 jam tertutup.</b> Pesan bebas tidak bisa dikirim; hanya template berbayar
                    yang boleh keluar. Cara termurah membukanya kembali: pelanggan membalas lebih dulu.

                    {{-- Jalan keluarnya diberikan di tempat kebuntuannya terbaca.
                         Sebelumnya kotak ini hanya menerangkan keadaan lalu
                         berhenti, dan yang tersisa bagi admin cuma membuka
                         WhatsApp di HP — persis hal yang membuat layar ini
                         berhenti dipakai.

                         BUKAN tombol "Kirim pancingan": pancingan itu satu
                         kalimat tetap yang berangkat tanpa ditinjau, dan di luar
                         jendela ia berbayar. Yang benar di sini adalah membuka
                         percakapan seperti nomor dingin — pilih templatenya,
                         lihat bunyinya, baru kirim. Karena itu tombolnya
                         MENGANTAR ke layar Chat Baru, bukan mengirim sendiri;
                         nomornya sudah dibawa serta supaya tidak perlu disalin. --}}
                    <div class="mt-2">
                        <button type="button"
                                @click="$dispatch('mulai-chat', @js(['nomor' => $terpilih->contact_key, 'nama' => $terpilih->namaTampil()]))"
                                class="text-xs px-2.5 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">
                            Mulai chat
                        </button>
                        <span class="text-[11px] text-gray-500 ml-1">
                            Anda diantar ke template <b>pembuka chat</b> yang sudah disetujui Meta &mdash;
                            berbayar, tapi satu-satunya yang boleh keluar di luar jendela.
                            Begitu pelanggan membalas, jendela 24 jamnya terbuka lagi dan Anda bisa mengetik bebas.
                        </span>
                    </div>
                </div>
            @endif

            {{-- ------------------------------------------------- menu aksi pesan --}}
            {{-- SATU menu dipakai bergantian oleh semua gelembung, dipindah ke
                 posisi gelembung yang diklik. Menaruh menunya sendiri-sendiri di
                 tiap gelembung berarti ratusan simpul tersembunyi di thread
                 panjang, dan tiap gelembung baru dari polling harus ikut
                 diinisialisasi Alpine — persis hal yang tidak dijamin untuk
                 markup yang disisipkan lewat insertAdjacentHTML. --}}
            <div x-show="menuAksi.tampil" x-cloak
                 @click.outside="tutupMenu()"
                 :style="`top:${menuAksi.y}px; left:${menuAksi.x}px`"
                 class="absolute z-40 w-40 bg-white border border-gray-200 rounded-lg shadow-lg py-1 text-sm">
                @if($terbuka)
                    <button type="button" x-show="menuAksi.bisaKutip" @click="mulaiKutip()"
                            class="w-full text-left px-3 py-2 hover:bg-gray-50">Balas</button>
                @endif
                <button type="button" x-show="menuAksi.bisaTerus" @click="bukaTeruskan()"
                        class="w-full text-left px-3 py-2 hover:bg-gray-50">Teruskan</button>
            </div>

            {{-- --------------------------------------------------- dialog teruskan --}}
            <div x-show="teruskan.tampil" x-cloak
                 class="absolute inset-0 z-40 flex items-center justify-center bg-black/30 p-4">
                <div class="w-full max-w-md bg-white rounded-lg shadow-xl flex flex-col max-h-full"
                     @click.outside="tutupTeruskan()">
                    <div class="shrink-0 flex items-center gap-2 px-3 py-2 border-b border-gray-200">
                        <div class="font-semibold text-sm">Teruskan ke…</div>
                        <button type="button" @click="tutupTeruskan()"
                                class="ml-auto px-2 text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
                    </div>

                    {{-- Isi yang akan diteruskan ditampilkan ulang di sini. Menu aksi
                         muncul di tempat kursor kebetulan berada, dan tanpa cuplikan
                         ini gampang sekali meneruskan gelembung yang salah. --}}
                    <div class="shrink-0 px-3 py-2 bg-gray-50 border-b border-gray-200">
                        <div class="text-[11px] font-semibold text-gray-500" x-text="teruskan.nama"></div>
                        <div class="text-[12px] text-gray-700 truncate" x-text="teruskan.ringkas"></div>
                    </div>

                    <div class="shrink-0 p-2 border-b border-gray-200">
                        <input type="text" x-model="teruskan.kata" x-ref="cariTujuan"
                               @input.debounce.400ms="muatTujuan()"
                               placeholder="Cari nama pelanggan atau nomor…"
                               class="w-full border rounded px-2 py-1.5 text-sm">
                    </div>

                    <div x-show="teruskan.galat" x-cloak x-text="teruskan.galat"
                         class="shrink-0 mx-2 mt-2 rounded border border-red-300 bg-red-50 px-2 py-1.5 text-xs text-red-700"></div>

                    <div x-show="teruskan.sukses" x-cloak x-text="teruskan.sukses"
                         class="shrink-0 mx-2 mt-2 rounded border border-emerald-300 bg-emerald-50 px-2 py-1.5 text-xs text-emerald-700"></div>

                    <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-gray-100">
                        <template x-if="teruskan.sibuk">
                            <p class="px-3 py-3 text-xs text-gray-400">Mencari…</p>
                        </template>

                        <template x-for="t in teruskan.hasil" :key="t.id">
                            <div class="flex items-center gap-2 px-3 py-2">
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium truncate" x-text="t.nama"></div>
                                    <div class="text-[11px] text-gray-500 truncate">
                                        <span x-text="t.nomor"></span>
                                        <span x-show="!t.terbuka" class="text-amber-700"> &middot; jendela tertutup</span>
                                    </div>
                                </div>
                                {{-- Tujuan berjendela tertutup tetap DITAMPILKAN tapi
                                     tombolnya mati: kalau disembunyikan, admin mengira
                                     kontaknya tidak ada dan mencarinya berulang kali. --}}
                                <button type="button" @click="kirimTeruskan(t)"
                                        :disabled="!t.terbuka || teruskan.mengirim"
                                        :title="t.terbuka ? 'Teruskan ke sini' : 'Jendela 24 jam tertutup — hanya template berbayar yang boleh keluar'"
                                        class="shrink-0 text-white text-xs px-3 py-1.5 rounded"
                                        :class="t.terbuka && !teruskan.mengirim
                                            ? 'bg-emerald-600 hover:bg-emerald-700'
                                            : 'bg-gray-300 cursor-not-allowed'">
                                    Teruskan
                                </button>
                            </div>
                        </template>

                        <template x-if="!teruskan.sibuk && teruskan.hasil.length === 0">
                            <p class="px-3 py-3 text-xs text-gray-500">Tidak ada percakapan yang cocok.</p>
                        </template>
                    </div>

                    {{-- Diucapkan terus terang di layar, bukan cuma di kode: yang
                         diteruskan tiba sebagai pesan BIASA. Cloud API tidak punya
                         penanda "Diteruskan", jadi label itu hanya ada di ERP. --}}
                    <div class="shrink-0 px-3 py-2 border-t border-gray-200 text-[11px] text-gray-500">
                        Isinya dikirim ulang sebagai pesan baru — penerima tidak melihat keterangan “Diteruskan”.
                    </div>
                </div>
            </div>

            {{-- Skrip thread berdiri DI LUAR cabang jendela-terbuka.
                 Dulu ia duduk di dalamnya, dan akibatnya threadCrm() tidak pernah
                 terdefinisi di percakapan yang jendelanya tertutup — x-data di
                 wadah paling luar gagal, lalu polling, gulir-ke-bawah, dan menu
                 aksi pesan ikut mati diam-diam persis di thread lama yang paling
                 sering dibuka untuk MENERUSKAN isinya. --}}
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
                            /*
                             * Kiriman diproses SATU per satu walau gelembungnya
                             * muncul serentak. Dua fetch bersamaan sama-sama
                             * membawa 'after' yang sama, jadi keduanya menerima
                             * kedua pesan itu — gelembungnya jadi dobel.
                             */
                            antre: [],

                            /*
                             * Zona jatuh sekolom penuh.
                             *
                             * Yang dihitung KEDALAMANNYA, bukan peristiwanya:
                             * dragenter/dragleave menyala tiap kali kursor
                             * melewati batas elemen anak, dan di layar yang penuh
                             * gelembung itu terjadi puluhan kali sedetik — tirainya
                             * berkedip hebat kalau dipasang langsung ke peristiwa.
                             */
                            seret: false,
                            dalamSeret: 0,

                            /*
                             * Pesan yang sedang dikutip (balas). Disimpan di sini,
                             * bukan di komposer, karena yang MEMICUNYA adalah menu
                             * di gelembung — dan gelembung hidup di luar form.
                             * Nilainya mengalir ke input tersembunyi 'reply_to'
                             * lewat rantai lingkup Alpine, jadi jalur kirim yang
                             * sudah ada tidak perlu diubah sama sekali.
                             */
                            kutipan: null,

                            /* Satu menu aksi, dipinjamkan ke gelembung yang diklik. */
                            menuAksi: {
                                tampil: false, x: 0, y: 0,
                                mid: null, pmid: '', nama: '', ringkas: '',
                                masuk: false, bisaKutip: false, bisaTerus: false,
                            },

                            teruskan: {
                                tampil: false, mid: null, nama: '', ringkas: '',
                                kata: '', hasil: [], sibuk: false, mengirim: false,
                                galat: '', sukses: '',
                            },

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

                                /*
                                 * Klik gelembung ditangani SATU pendengar di wadah
                                 * penggulir, bukan atribut per gelembung. Gelembung
                                 * baru datang dari insertAdjacentHTML (kirim &
                                 * polling); markup yang disisipkan begitu tidak
                                 * dijamin ikut dipasangi penangan, dan gejalanya
                                 * paling menipu: tombol yang terlihat normal tapi
                                 * diam saja — hanya pada pesan yang baru masuk.
                                 */
                                this.$refs.gulir.addEventListener('click', (e) => {
                                    const tombol = e.target.closest('[data-aksi-pesan]');

                                    if (tombol) {
                                        // Ditahan di sini supaya klik yang MEMBUKA menu
                                        // tidak ikut terbaca sebagai klik di luar menu,
                                        // yang langsung menutupnya lagi.
                                        e.stopPropagation();
                                        this.bukaMenu(tombol);
                                        return;
                                    }

                                    const kutipan = e.target.closest('[data-lompat]');
                                    if (kutipan) this.lompatKe(kutipan.dataset.lompat);
                                });

                                this.$refs.gulir.addEventListener('keydown', (e) => {
                                    if (e.key !== 'Enter' && e.key !== ' ') return;

                                    const kutipan = e.target.closest('[data-lompat]');
                                    if (kutipan) { e.preventDefault(); this.lompatKe(kutipan.dataset.lompat); }
                                });

                                // Menu ditaruh pada koordinat tetap; begitu threadnya
                                // digulir, tempatnya tidak lagi menunjuk gelembung
                                // mana pun.
                                this.$refs.gulir.addEventListener('scroll', () => this.tutupMenu(), { passive: true });

                                document.addEventListener('keydown', (e) => {
                                    if (e.key !== 'Escape') return;

                                    // Satu Esc membatalkan satu hal, mulai dari yang
                                    // paling atas — kebiasaan yang sama di mana pun.
                                    if (this.teruskan.tampil) return this.tutupTeruskan();
                                    if (this.menuAksi.tampil) return this.tutupMenu();
                                    if (this.kutipan) this.batalKutip();
                                });
                            },

                            /* ------------------------------------------ aksi pesan */

                            bukaMenu(tombol) {
                                const baris = tombol.closest('[data-mid]');
                                if (!baris) return;

                                const rt = tombol.getBoundingClientRect();
                                const rw = this.$root.getBoundingClientRect();

                                // Ukuran menu dipatok, bukan diukur: saat hendak
                                // dipindahkan ia masih tersembunyi dan tingginya nol,
                                // jadi pengukuran hidup selalu menjawab 0 dan menunya
                                // tersangkut di tepi bawah.
                                const lebar = 160;
                                const tinggi = 84;

                                let x = rt.right - rw.left - lebar;
                                let y = rt.bottom - rw.top + 4;

                                // Gelembung di dasar layar: menunya dibalik ke atas
                                // supaya tidak terpotong tepi thread.
                                if (y + tinggi > rw.height) y = rt.top - rw.top - tinggi - 4;

                                this.menuAksi = {
                                    tampil: true,
                                    x: Math.max(8, Math.min(x, rw.width - lebar - 8)),
                                    y: Math.max(8, y),
                                    mid: baris.dataset.mid,
                                    pmid: baris.dataset.pmid || '',
                                    nama: baris.dataset.nama || '',
                                    ringkas: baris.dataset.ringkas || '',
                                    masuk: baris.dataset.masuk === '1',
                                    bisaKutip: baris.dataset.bisaKutip === '1',
                                    bisaTerus: baris.dataset.bisaTerus === '1',
                                };
                            },

                            tutupMenu() { this.menuAksi.tampil = false; },

                            mulaiKutip() {
                                this.kutipan = {
                                    pmid: this.menuAksi.pmid,
                                    nama: this.menuAksi.nama,
                                    ringkas: this.menuAksi.ringkas,
                                    masuk: this.menuAksi.masuk,
                                };
                                this.tutupMenu();
                                this.$nextTick(() => this.$root.querySelector('textarea[name=\'teks\']')?.focus());
                            },

                            batalKutip() { this.kutipan = null; },

                            /*
                             * Melompat ke pesan yang dikutip, lalu menyorotnya sebentar.
                             * Tanpa sorotan, lompatan di tengah thread padat terasa
                             * seperti layar yang bergeser sendiri tanpa sebab.
                             */
                            lompatKe(mid) {
                                const el = this.$refs.gulir.querySelector('[data-mid=\'' + mid + '\']');

                                if (!el) return;

                                el.scrollIntoView({ behavior: 'smooth', block: 'center' });

                                const kotak = el.firstElementChild;
                                kotak.classList.add('ring-2', 'ring-emerald-400');
                                setTimeout(() => kotak.classList.remove('ring-2', 'ring-emerald-400'), 1400);
                            },

                            /* -------------------------------------------- teruskan */

                            bukaTeruskan() {
                                this.teruskan = {
                                    tampil: true,
                                    mid: this.menuAksi.mid,
                                    nama: this.menuAksi.nama,
                                    ringkas: this.menuAksi.ringkas,
                                    kata: '', hasil: [], sibuk: false, mengirim: false,
                                    galat: '', sukses: '',
                                };
                                this.tutupMenu();
                                this.muatTujuan();
                                this.$nextTick(() => this.$refs.cariTujuan?.focus());
                            },

                            tutupTeruskan() { this.teruskan.tampil = false; },

                            async muatTujuan() {
                                this.teruskan.sibuk = true;

                                try {
                                    const url = '{{ route('crm.percakapan.cari') }}?kecuali={{ $terpilih->id }}&q='
                                              + encodeURIComponent(this.teruskan.kata);
                                    const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                                    const d = await r.json();
                                    this.teruskan.hasil = d.hasil ?? [];
                                } catch (e) {
                                    this.teruskan.galat = 'Gagal memuat daftar tujuan — periksa koneksi.';
                                }

                                this.teruskan.sibuk = false;
                            },

                            async kirimTeruskan(tujuan) {
                                if (!tujuan.terbuka || this.teruskan.mengirim) return;

                                this.teruskan.mengirim = true;
                                this.teruskan.galat = '';

                                try {
                                    const r = await fetch('/erp/crm/pesan/' + this.teruskan.mid + '/teruskan', {
                                        method: 'POST',
                                        headers: {
                                            'Accept': 'application/json',
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                                        },
                                        body: JSON.stringify({ tujuan_id: tujuan.id }),
                                    });
                                    const d = await r.json().catch(() => ({}));

                                    if (!r.ok || !d.success) {
                                        this.teruskan.galat = d.pesan || 'Gagal meneruskan pesan.';
                                    } else {
                                        /*
                                         * Kabar berhasil ditahan sebentar DI DIALOG,
                                         * bukan lewat toast: pesannya mendarat di
                                         * percakapan LAIN, jadi tidak ada apa pun di
                                         * layar ini yang berubah sebagai bukti — dan
                                         * dialog yang cuma menutup diri gampang
                                         * dikira gagal, lalu diulang.
                                         */
                                        this.teruskan.sukses = d.pesan || 'Pesan diteruskan.';
                                        setTimeout(() => this.tutupTeruskan(), 1500);
                                    }
                                } catch (e) {
                                    this.teruskan.galat = 'Jaringan bermasalah — pesan belum diteruskan.';
                                }

                                this.teruskan.mengirim = false;
                            },

                            destroy() { clearInterval(this.jam); },

                            mulaiSeret(e) {
                                // Menyeret teks, tautan, atau gambar dari tab lain
                                // juga memicu dragenter. Yang ditunggu cuma BERKAS;
                                // tanpa saringan ini tirainya muncul saat admin
                                // sekadar menyorot kalimat lalu menariknya.
                                if (! [...(e.dataTransfer?.types ?? [])].includes('Files')) return;

                                this.dalamSeret++;
                                this.seret = true;
                            },

                            akhiriSeret() {
                                if (! this.seret) return;

                                if (--this.dalamSeret <= 0) {
                                    this.dalamSeret = 0;
                                    this.seret = false;
                                }
                            },

                            /*
                             * Berkasnya dilempar sebagai peristiwa, bukan disodorkan
                             * langsung ke komposer: zona jatuh ini tidak perlu tahu
                             * apa pun soal isi dalaman kotak ketik — termasuk batas
                             * jumlah, batas ukuran, dan pratinjaunya, yang semuanya
                             * sudah dijaga di sana lewat jalur tempel & dialog.
                             */
                            jatuhBerkas(e) {
                                this.dalamSeret = 0;
                                this.seret = false;

                                const berkas = [...(e.dataTransfer?.files ?? [])];

                                if (berkas.length) {
                                    this.$dispatch('berkas-jatuh', { berkas });
                                }
                            },

                            async tarik() {
                                if (document.hidden || this.sibuk) return;

                                try {
                                    const url = '/erp/crm/' + percakapanId + '/pesan-baru?after=' + this.terakhir;
                                    const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                                    if (!r.ok) return;

                                    const d = await r.json();
                                    if (d.html) this.tempelHtml(d.html, d.last_id);
                                    if (d.centang) this.perbaruiCentang(d.centang);
                                } catch (e) { /* jaringan putus sesaat: coba lagi siklus berikutnya */ }
                            },

                            /*
                             * Kirim satu foto etalase berikut captionnya, atas
                             * permintaan panel Produk. Hasilnya dipantulkan
                             * kembali lewat 'foto-produk-selesai' supaya
                             * tombolnya di panel tahu kapan berhenti berputar.
                             */
                            async kirimFotoProduk(detail) {
                                const selesai = (ok, error) => window.dispatchEvent(
                                    new CustomEvent('foto-produk-selesai', { detail: { ok, error } })
                                );

                                try {
                                    const r = await fetch('/erp/crm/' + percakapanId + '/kirim-foto', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                                        },
                                        body: JSON.stringify({
                                            foto: detail.foto,
                                            caption: detail.caption,
                                            after: this.terakhir,
                                        }),
                                    });
                                    const d = await r.json().catch(() => ({}));

                                    if (!r.ok || !d.success) {
                                        const rinci = d.errors ? Object.values(d.errors).flat().join(' ') : '';
                                        selesai(false, d.error || rinci || 'Foto gagal dikirim.');
                                        return;
                                    }

                                    if (d.html) this.tempelHtml(d.html, d.last_id);
                                    this.keBawah();
                                    selesai(true, null);
                                } catch (e) {
                                    selesai(false, 'Jaringan bermasalah — foto belum terkirim.');
                                }
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

                            /*
                             * Kirim optimistis: gelembungnya muncul SEKARANG dengan
                             * tanda jam, kotak ketik langsung kosong, pengirimannya
                             * menyusul di belakang layar.
                             *
                             * Menahan layar sampai server menjawab membuat balasan
                             * beruntun terasa tersendat, dan selama menunggu admin
                             * tidak punya bukti tombolnya sudah kena — itu yang
                             * memancing kirim dua kali.
                             */
                            kirim(form) {
                                const ta = form.querySelector('textarea[name="teks"]');
                                const teks = (ta?.value ?? '').trim();
                                const berkas = [...(form.querySelector('input[type="file"]')?.files ?? [])];

                                if (!teks && berkas.length === 0) return;

                                // Isi form dibekukan SEBELUM dikosongkan — FormData
                                // menyalin nilainya saat ini juga, jadi reset() di
                                // bawah tidak ikut mengosongkan muatan yang antre.
                                const data = new FormData(form);

                                /*
                                 * Kunci sekali-kirim, dipakai ulang apa adanya oleh
                                 * "Coba lagi". Kalau kegagalannya cuma jawaban yang
                                 * hilang di jaringan, percobaan kedua dikenali server
                                 * dan pelanggan tidak menerima pesan yang sama dua kali.
                                 *
                                 * randomUUID hanya ada di konteks aman (https/localhost);
                                 * cadangannya tetap cukup unik untuk umur 10 menit.
                                 */
                                data.set('kirim_key', crypto.randomUUID
                                    ? crypto.randomUUID()
                                    : Date.now() + '-' + Math.random().toString(36).slice(2));

                                this.galat = '';
                                /*
                                 * Kutipannya dipegang dulu sebelum dilepas: gelembung
                                 * sementara harus memperlihatkan kotak kutipan yang
                                 * sama seperti gelembung asli nanti, kalau tidak
                                 * gelembungnya terlihat "melompat" bertambah tinggi
                                 * saat jawaban server datang.
                                 */
                                const kutipan = this.kutipan;
                                const gelembung = this.gelembungSementara(teks, berkas, kutipan);

                                form.reset();
                                this.batalKutip();
                                form.dispatchEvent(new CustomEvent('balasan-terkirim'));
                                this.keBawah();

                                this.antre.push({ data, gelembung, form });
                                this.pompa();
                            },

                            async pompa() {
                                if (this.sibuk) return;
                                this.sibuk = true;

                                while (this.antre.length) {
                                    await this.kirimSatu(this.antre.shift());
                                }

                                this.sibuk = false;
                            },

                            async kirimSatu(tugas) {
                                // 'after' diisi saat GILIRANNYA tiba, bukan saat
                                // diantrekan: pesan sebelum ini mungkin sudah
                                // menggeser id terakhir.
                                tugas.data.set('after', this.terakhir);

                                try {
                                    const r = await fetch(tugas.form.action, {
                                        method: 'POST',
                                        body: tugas.data,
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
                                        this.tandaiGagal(tugas, d.error || rinci || d.message || 'Gagal mengirim. Coba lagi.');
                                        return;
                                    }

                                    this.buangSementara(tugas.gelembung);
                                    if (d.html) this.tempelHtml(d.html, d.last_id);
                                    this.keBawah();
                                } catch (e) {
                                    this.tandaiGagal(tugas, 'Jaringan bermasalah — pesan belum terkirim.');
                                }
                            },

                            /*
                             * Gelembung sementara sengaja dibuat ringkas: umurnya
                             * beberapa detik lalu DIGANTI gelembung asli dari server.
                             * Yang wajib sama cuma bentuk luarnya — teks, lampiran,
                             * jam — supaya pergantiannya tidak terlihat berkedip.
                             */
                            gelembungSementara(teks, berkas, kutipan = null) {
                                const luar = document.createElement('div');
                                luar.className = 'flex justify-end';

                                const kotak = document.createElement('div');
                                // pr-8 menyamai gelembung server, yang menyisakan ruang
                                // untuk chevron aksi. Tanpa itu teksnya bergeser sesaat
                                // ketika gelembung sementara diganti yang asli.
                                kotak.className = 'max-w-[80%] rounded-lg pl-3 pr-8 py-2 text-sm shadow-sm bg-[#d9fdd3]';
                                luar.appendChild(kotak);

                                if (kutipan) kotak.appendChild(this.kutipanBaru(kutipan));

                                if (teks) {
                                    const t = document.createElement('div');
                                    t.className = 'whitespace-pre-wrap';
                                    // textContent, bukan innerHTML: apa pun yang diketik
                                    // admin tidak boleh berubah jadi markup.
                                    t.textContent = teks;
                                    kotak.appendChild(t);
                                }

                                const urls = [];
                                for (const f of berkas) {
                                    const baris = document.createElement('div');
                                    baris.className = 'mt-2';

                                    if ((f.type || '').startsWith('image/')) {
                                        const url = URL.createObjectURL(f);
                                        urls.push(url);
                                        const img = document.createElement('img');
                                        img.src = url;
                                        img.className = 'rounded border max-h-56';
                                        baris.appendChild(img);
                                    } else {
                                        const keping = document.createElement('div');
                                        keping.className = 'inline-block border border-gray-300 rounded px-2 py-1 text-xs';
                                        keping.textContent = '⬇ ' + f.name;
                                        baris.appendChild(keping);
                                    }
                                    kotak.appendChild(baris);
                                }
                                // Dilepas saat gelembungnya dibuang; kalau dilepas
                                // begitu gambar termuat, gambar yang sama gagal
                                // digambar ulang saat kolomnya diukur ulang.
                                luar._urlSementara = urls;

                                const kaki = document.createElement('div');
                                kaki.className = 'mt-1 text-[11px] text-gray-500 flex items-center gap-2 justify-end';
                                kaki.dataset.kaki = '1';
                                const jam = document.createElement('span');
                                jam.textContent = this.jamSekarang();
                                kaki.appendChild(jam);
                                kaki.appendChild(this.centangBaru());
                                kotak.appendChild(kaki);

                                this.$refs.tambahan.insertAdjacentElement('beforebegin', luar);
                                this.kosong = false;
                                return luar;
                            },

                            /*
                             * Kembaran markup _kutipan.blade.php. Digambar tangan di
                             * sini karena gelembung sementara memang tidak pernah
                             * melewati server — dan bentuknya WAJIB sama persis,
                             * kalau tidak pergantian ke gelembung asli terlihat
                             * berkedip.
                             */
                            kutipanBaru(kutipan) {
                                const luar = document.createElement('div');
                                luar.className = 'mb-1.5 flex gap-2 rounded overflow-hidden bg-black/5';

                                const pita = document.createElement('div');
                                pita.className = 'w-1 shrink-0 ' + (kutipan.masuk ? 'bg-sky-500' : 'bg-emerald-500');
                                luar.appendChild(pita);

                                const isi = document.createElement('div');
                                isi.className = 'min-w-0 py-1 pr-2';

                                const nama = document.createElement('div');
                                nama.className = 'text-[11px] font-semibold '
                                               + (kutipan.masuk ? 'text-sky-700' : 'text-emerald-700');
                                nama.textContent = kutipan.nama;

                                const ringkas = document.createElement('div');
                                ringkas.className = 'text-[12px] text-gray-600 truncate';
                                ringkas.textContent = kutipan.ringkas;

                                isi.appendChild(nama);
                                isi.appendChild(ringkas);
                                luar.appendChild(isi);

                                return luar;
                            },

                            centangBaru() {
                                return document.getElementById('crm-centang-cetak')
                                    .content.firstElementChild.cloneNode(true);
                            },

                            buangSementara(el) {
                                (el._urlSementara || []).forEach(u => URL.revokeObjectURL(u));
                                el.remove();
                            },

                            /*
                             * Gelembung yang gagal DIBIARKAN di layar, bukan dihapus
                             * diam-diam: setelah kotak ketik dikosongkan, isinya satu-
                             * satunya salinan yang tersisa. Tombolnya mengantre ulang
                             * muatan yang sama persis, jadi lampiran tak perlu
                             * dipilih ulang.
                             */
                            tandaiGagal(tugas, pesan) {
                                this.galat = pesan;

                                const kotak = tugas.gelembung.firstElementChild;
                                const kaki  = kotak.querySelector('[data-kaki]');

                                kotak.classList.add('ring-1', 'ring-red-300');
                                kaki.querySelector('.crm-centang')?.remove();
                                if (kaki.querySelector('[data-ulang]')) return;

                                const tanda = document.createElement('span');
                                tanda.className = 'text-red-600';
                                tanda.textContent = 'gagal';
                                kaki.appendChild(tanda);

                                const ulang = document.createElement('button');
                                ulang.type = 'button';
                                ulang.dataset.ulang = '1';
                                ulang.className = 'text-emerald-700 underline hover:no-underline';
                                ulang.textContent = 'Coba lagi';
                                ulang.addEventListener('click', () => {
                                    ulang.remove();
                                    tanda.remove();
                                    kotak.classList.remove('ring-1', 'ring-red-300');
                                    kaki.appendChild(this.centangBaru());
                                    this.galat = '';
                                    this.antre.push(tugas);
                                    this.pompa();
                                });
                                kaki.appendChild(ulang);
                            },

                            /*
                             * Bentuknya dicocokkan dengan gelembung server ('d M Y H:i')
                             * supaya barisnya tidak berubah saat yang sementara diganti
                             * yang asli.
                             */
                            jamSekarang() {
                                const bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                                const d = new Date();
                                const p = n => String(n).padStart(2, '0');

                                return p(d.getDate()) + ' ' + bulan[d.getMonth()] + ' ' + d.getFullYear()
                                     + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
                            },

                            /*
                             * Centang naik jadi dua lalu biru lewat polling yang sudah
                             * jalan — status 'delivered'/'read' datang dari webhook
                             * BERMENIT setelah pesannya dikirim, jadi tidak mungkin
                             * ikut jawaban kirim.
                             */
                            perbaruiCentang(daftar) {
                                const judul = {
                                    terkirim: 'Terkirim ke WhatsApp',
                                    sampai:   'Sampai di HP pelanggan',
                                    dibaca:   'Dibaca pelanggan',
                                };

                                for (const [id, status] of Object.entries(daftar)) {
                                    const el = this.$refs.gulir.querySelector('[data-mid="' + id + '"] .crm-centang');
                                    if (!el || el.dataset.status === status) continue;

                                    el.dataset.status = status;
                                    el.title = judul[status] ?? '';
                                }
                            },
                        };
                    }

                    function komposerCrm(potongan) {
                        return {
                            daftar: [],
                            nomor: 0,
                            menu: false,

                            // --- template balasan ("/" atau tombol gelembung) ---
                            tpl: potongan || [],
                            tplBuka: false,
                            tplCari: '',
                            tplPilih: 0,

                            /*
                             * Isi template ikut dicari, bukan cuma judulnya:
                             * yang diingat admin biasanya kalimatnya ("ongkir
                             * gratis"), bukan nama yang diberikan orang lain
                             * waktu menyimpannya.
                             */
                            get tplHasil() {
                                const q = this.tplCari.trim().toLowerCase();
                                if (! q) return this.tpl;
                                return this.tpl.filter(t =>
                                    (t.judul + ' ' + (t.grup || '') + ' ' + t.teks).toLowerCase().includes(q));
                            },

                            /*
                             * "/" hanya dibajak saat kotaknya KOSONG. Garis miring
                             * itu huruf biasa di tengah kalimat ("30x30 / kotak"),
                             * dan menu yang menyembul di sana cuma menghalangi.
                             */
                            pintasTemplate(e) {
                                if (e.key !== '/' || e.target.value !== '' || e.isComposing) return;
                                e.preventDefault();
                                this.bukaTemplate();
                            },

                            bukaTemplate() {
                                this.tplBuka = true;
                                this.tplCari = '';
                                this.tplPilih = 0;
                                this.$nextTick(() => this.$refs.tplCari?.focus());
                            },

                            tutupTemplate(fokusKembali = true) {
                                if (! this.tplBuka) return;
                                this.tplBuka = false;
                                if (fokusKembali) this.$nextTick(() => this.$refs.teks?.focus());
                            },

                            /*
                             * Semua tombol dijaga sendiri: kotak cari ini duduk DI
                             * DALAM form balasan, jadi Enter yang dibiarkan lewat
                             * akan mengirim balasan yang belum ditulis.
                             */
                            tplNavigasi(e) {
                                const n = this.tplHasil.length;

                                if (e.key === 'ArrowDown') {
                                    e.preventDefault();
                                    this.tplPilih = n ? (this.tplPilih + 1) % n : 0;
                                } else if (e.key === 'ArrowUp') {
                                    e.preventDefault();
                                    this.tplPilih = n ? (this.tplPilih - 1 + n) % n : 0;
                                } else if (e.key === 'Enter') {
                                    e.preventDefault();
                                    const t = this.tplHasil[this.tplPilih];
                                    if (t) this.pakaiTemplate(t);
                                } else if (e.key === 'Escape') {
                                    e.preventDefault();
                                    this.tutupTemplate();
                                } else {
                                    // Daftar menyusut saat mengetik; sorotan yang
                                    // tertinggal di baris ke-7 jadi tak kelihatan.
                                    this.tplPilih = 0;
                                }
                            },

                            /*
                             * DISISIPKAN, bukan dikirim langsung: potongan teks
                             * hampir selalu perlu disunting sedikit, dan tombol
                             * yang langsung mengirim meloloskan kalimat setengah
                             * jadi ke pelanggan.
                             */
                            pakaiTemplate(t) {
                                this.tutupTemplate(false);
                                this.sisip(t.teks);
                            },

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
                                this.daftar.forEach(g => g.url && URL.revokeObjectURL(g.url));
                                this.daftar = [];
                                this.sinkron();
                                this.tumbuh();
                            },

                            tempel(e) {
                                const berkas = [...(e.clipboardData?.files || [])];
                                if (berkas.length) { e.preventDefault(); this.tambah(berkas); }
                            },

                            dariDialog() {
                                // Isi input dibaca lalu disusun ulang lewat jalur yang sama,
                                // supaya gambar yang dipilih lewat dialog bisa dibuang satu-satu
                                // persis seperti yang ditempel.
                                this.tambah([...this.$refs.berkas.files], true);
                            },

                            /*
                             * Batas WhatsApp per JENIS — sama persis dengan MediaKind di
                             * sisi server. Diperiksa di sini juga supaya berkas kebesaran
                             * ditolak SEBELUM diunggah, bukan setelah menunggu lama.
                             */
                            batas(f) {
                                const t = (f.type || '').toLowerCase();
                                if (['image/jpeg', 'image/jpg', 'image/png'].includes(t)) return 5;
                                if (t.startsWith('video/') || t.startsWith('audio/')) return 16;
                                return 100;
                            },

                            ukuranTerbaca(bytes) {
                                return bytes >= 1024 * 1024
                                    ? (bytes / 1024 / 1024).toFixed(1) + ' MB'
                                    : Math.max(1, Math.round(bytes / 1024)) + ' KB';
                            },

                            tambah(berkas, dariDialog = false) {
                                // SEMUA jenis diterima — dokumen kerja (PDF, DXF, RLD)
                                // justru yang paling sering dikirim. Menyaring ke gambar
                                // saja membuat berkas hilang diam-diam tanpa pesan apa pun.
                                if (!berkas.length) { if (dariDialog) this.sinkron(); return; }

                                if (this.daftar.length + berkas.length > 5) {
                                    alert('Maksimal 5 lampiran sekali kirim.');
                                    return this.sinkron();
                                }

                                for (const f of berkas) {
                                    const maks = this.batas(f);

                                    if (f.size > maks * 1024 * 1024) {
                                        alert('"' + f.name + '" melebihi batas ' + maks + ' MB untuk jenis berkas ini.');
                                        continue;
                                    }

                                    this.daftar.push({
                                        key: ++this.nomor,
                                        file: f,
                                        // Pratinjau hanya untuk gambar; sisanya keping bernama.
                                        url: f.type.startsWith('image/') ? URL.createObjectURL(f) : null,
                                        ukuran: this.ukuranTerbaca(f.size),
                                    });
                                }
                                this.sinkron();
                            },

                            buang(i) {
                                if (this.daftar[i].url) URL.revokeObjectURL(this.daftar[i].url);
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
    </div>
</div>
