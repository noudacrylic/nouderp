@extends('layouts.erp')

{{-- Template pesan yang DIPILIH MANUSIA. Yang dikirim sistem sendiri ada di
     layar Notifikasi Pesanan berikut tombol pengajuannya ke Meta — memisahkan
     keduanya berdasarkan siapa yang mengirim, bukan berdasarkan harganya,
     adalah seluruh alasan layar ini disusun ulang. --}}
@section('content')
@php
    $bentuk = fn ($t) => [
        'id'        => $t?->id,
        'title'     => $t?->title ?? '',
        'body'      => $t?->body ?? '',
        'category'  => $t?->category ?? '',
        'sort'      => $t?->sort_order ?? 0,
        'aktif'     => $t ? (bool) $t->is_active : true,
        'keMeta'    => $t ? $t->keMeta() : false,
        'metaName'  => $t?->meta_name ?? '',
        'terkunci'  => $t ? $t->keMeta() : false,
    ];
@endphp

<div x-data="templateCrm(@js($bentuk(null)))">

    <div class="flex flex-wrap items-center gap-2 mb-4">
        <h1 class="text-lg font-semibold">Template Pesan</h1>
        <div class="ml-auto flex items-center gap-2">
            <button type="button" @click="buka(null)"
                    class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-2 rounded text-sm">
                + Tambah Template
            </button>
            <a href="{{ route('crm.template.baru') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Chat Baru</a>
        </div>
    </div>

    <div class="mb-4 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
        Kalimat yang <b>Anda pilih sendiri</b> saat membalas chat — nomor rekening, alamat, jam buka.
        Yang dikirim sistem otomatis (pembayaran, resi, tagihan, pancingan) ada di
        <a href="{{ route('crm.notifikasi.index') }}" class="text-blue-700 underline">Notifikasi Pesanan</a>,
        berikut pengajuan templatenya ke Meta.
    </div>

    @if($galatMeta)
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            Status Meta tidak bisa disegarkan: {{ $galatMeta }} — yang tertera di bawah adalah catatan terakhir kita.
        </div>
    @endif

    @if($dryRun)
        <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            <b>Mode aman menyala.</b> Pengajuan ke Meta hanya dicatat, tidak benar-benar dikirim.
        </div>
    @endif

    @if($daftar->isEmpty())
        <div class="rounded border border-gray-300 bg-white px-3 py-6 text-center text-sm text-gray-500">
            Belum ada template. Mulai dari yang paling sering Anda ketik ulang — nomor rekening, alamat toko.
        </div>
    @endif

    @foreach($kelompok as $namaKelompok => $isiKelompok)
        <h2 class="text-sm font-bold uppercase tracking-wider text-gray-500 mb-2 mt-6">{{ $namaKelompok }}</h2>

        <div class="space-y-3">
            @foreach($isiKelompok as $t)
                <div class="bg-white rounded shadow p-3 {{ $t->is_active ? '' : 'opacity-60' }}">
                    <div class="flex flex-wrap items-center gap-2 mb-1">
                        <span class="font-semibold text-sm">{{ $t->title }}</span>

                        @if($t->keMeta())
                            <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs"
                                  title="Boleh dipakai membuka chat ke nomor yang belum menghubungi kita">
                                Meta · {{ $t->meta_name }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-xs
                                {{ $t->meta_status === \App\Modules\CRM\Models\CrmTemplate::META_APPROVED ? 'bg-green-100 text-green-700'
                                   : ($t->meta_status === \App\Modules\CRM\Models\CrmTemplate::META_REJECTED ? 'bg-red-100 text-red-700'
                                   : ($t->meta_status ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600')) }}">
                                {{ $t->meta_status ?: 'belum diajukan' }}
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-xs"
                                  title="Gratis, tapi hanya sah dikirim di dalam jendela 24 jam">
                                balasan cepat
                            </span>
                        @endif

                        @unless($t->is_active)
                            <span class="px-2 py-0.5 rounded-full bg-gray-200 text-gray-600 text-xs">nonaktif</span>
                        @endunless
                    </div>

                    <div class="rounded border bg-gray-50 px-3 py-2 text-sm text-gray-700 whitespace-pre-wrap">{{ $t->body }}</div>

                    @if($t->meta_error)
                        {{-- Alasan kegagalan pengajuan ditulis di barisnya, bukan sekadar
                             dikedipkan sebagai pesan flash: yang mengajukan besok pagi
                             harus tahu kenapa yang kemarin tidak jadi. --}}
                        <p class="mt-1 text-xs text-red-700">Pengajuan terakhir gagal: {{ $t->meta_error }}</p>
                    @endif

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" @click="buka(@js($bentuk($t)))"
                                class="text-xs px-2.5 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50">
                            Ubah
                        </button>

                        @if($t->keMeta() && ! $t->meta_status)
                            <form method="POST" action="{{ route('crm.template.ajukan', $t) }}"
                                  onsubmit="return confirm('Ajukan &quot;{{ $t->title }}&quot; ke Meta? Bunyinya terkunci setelah ini.')">
                                @csrf
                                <button class="text-xs px-2.5 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">
                                    Ajukan ke Meta
                                </button>
                            </form>
                            <span class="text-[11px] text-gray-500">Sekali diajukan, bunyinya hanya bisa diubah dari dasbor vendor.</span>
                        @endif

                        @unless($t->keMeta())
                            <form method="POST" action="{{ route('crm.template.destroy', $t) }}"
                                  onsubmit="return confirm('Hapus template &quot;{{ $t->title }}&quot;?')">
                                @csrf @method('DELETE')
                                <button class="text-xs px-2.5 py-1 rounded border border-red-300 text-red-700 hover:bg-red-50">Hapus</button>
                            </form>
                        @endunless
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- ------------------------------------------------------------- formulir --}}
    {{-- SATU formulir dipakai tambah & ubah. Dua formulir terpisah berarti dua
         tempat yang harus ikut berubah tiap kali sebuah aturan bertambah, dan
         yang satu selalu tertinggal. --}}
    <div x-show="tampil" x-cloak class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
        <div @click.outside="tutup()" class="bg-white rounded shadow-lg w-full max-w-2xl mt-8">
            <form method="POST" :action="aksi()" class="p-4 space-y-3">
                @csrf
                <template x-if="form.id"><input type="hidden" name="_method" value="POST"></template>

                <h2 class="font-semibold" x-text="form.id ? 'Ubah Template' : 'Template Baru'"></h2>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Judul</label>
                        <input type="text" name="title" x-model="form.title" required maxlength="120"
                               class="w-full border rounded px-3 py-2 text-sm" placeholder="Nomor rekening">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Kelompok</label>
                        <input type="text" name="category" x-model="form.category" maxlength="60"
                               class="w-full border rounded px-3 py-2 text-sm" placeholder="Pembayaran">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Isi pesan</label>
                    <textarea name="body" x-model="form.body" rows="5" required maxlength="2000"
                              :readonly="form.terkunci"
                              class="w-full border rounded px-3 py-2 text-sm"
                              :class="form.terkunci ? 'bg-gray-100 text-gray-500' : ''"></textarea>

                    <p x-show="form.terkunci" x-cloak class="mt-1 text-xs text-amber-700">
                        Terkunci — template ini sudah diajukan ke Meta, dan yang benar-benar dikirim ke
                        pelanggan sejak itu adalah salinan milik Meta. Ubah dari dasbor vendor.
                    </p>

                    <div x-show="!form.terkunci && !form.keMeta" x-cloak class="mt-1 text-xs text-gray-500">
                        Isian yang bisa dipakai:
                        @foreach($isian as $kode => $arti)
                            <code class="px-1 bg-gray-100 rounded">{{ $kode }}</code><span class="text-gray-400">{{ !$loop->last ? ',' : '' }}</span>
                        @endforeach
                    </div>
                    <div x-show="!form.terkunci && form.keMeta" x-cloak class="mt-1 text-xs text-gray-500">
                        Untuk Meta pakai <code class="px-1 bg-gray-100 rounded">&#123;&#123;1&#125;&#125;</code>,
                        <code class="px-1 bg-gray-100 rounded">&#123;&#123;2&#125;&#125;</code>, … berurutan.
                        Jangan diawali atau diakhiri variabel, dan jangan dua variabel berdampingan — Meta menolaknya.
                    </div>
                </div>

                {{-- Penanda Meta hanya bisa dipasang selama template BELUM diajukan.
                     Sesudahnya ia bukan lagi keputusan kita. --}}
                <div class="rounded border border-gray-200 bg-gray-50 p-3 space-y-2">
                    <label class="flex items-start gap-2 text-sm"
                           :class="form.terkunci ? 'opacity-60' : ''">
                        <input type="checkbox" name="ke_meta" value="1" class="mt-0.5"
                               x-model="form.keMeta" :disabled="form.terkunci">
                        <span>
                            <b>Daftarkan ke Meta</b> — supaya bisa dipakai <b>membuka chat</b> ke nomor
                            yang belum menghubungi kita 24 jam terakhir.
                            <span class="block text-xs text-gray-500">
                                Berbayar per kirim dan perlu persetujuan Meta (bisa sampai 24 jam).
                                Tanpa ini, template tetap gratis tapi hanya sah dipakai di dalam jendela 24 jam.
                            </span>
                        </span>
                    </label>

                    <div x-show="form.keMeta" x-cloak>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Nama di Meta</label>
                        <input type="text" name="meta_name" x-model="form.metaName" maxlength="120"
                               :readonly="form.terkunci" pattern="[a-z0-9_]+"
                               class="w-full border rounded px-3 py-2 text-sm font-mono"
                               :class="form.terkunci ? 'bg-gray-100 text-gray-500' : ''"
                               placeholder="sapa_umum">
                        <p class="text-xs text-gray-400 mt-1">Huruf kecil, angka, garis bawah. Tidak bisa diubah setelah diajukan.</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1" x-model="form.aktif">
                        Aktif (muncul di rail chat)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        Urutan
                        <input type="number" name="sort_order" x-model="form.sort" min="0" max="9999"
                               class="w-20 border rounded px-2 py-1 text-sm">
                    </label>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <button class="bg-blue-600 text-white px-4 py-2 rounded text-sm">Simpan</button>
                    <button type="button" @click="tutup()" class="px-3 py-2 rounded text-sm text-gray-600 hover:bg-gray-100">Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function templateCrm(kosong) {
        return {
            tampil: false,
            bawaan: kosong,
            form: { ...kosong },

            buka(t) {
                // Disalin, bukan dirujuk: tanpa ini, membatalkan sebuah suntingan
                // tetap meninggalkan perubahannya di kartu yang ada di belakang.
                this.form = { ...(t || this.bawaan) };
                this.tampil = true;
            },

            tutup() { this.tampil = false; },

            aksi() {
                return this.form.id
                    ? '{{ url('erp/crm/template') }}/' + this.form.id
                    : '{{ route('crm.template.store') }}';
            },
        };
    }
</script>
@endsection
