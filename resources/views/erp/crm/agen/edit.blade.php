@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">{{ $agen->nama }}</h1>
    <a href="{{ route('crm.agen.index') }}" class="text-sm text-emerald-700 hover:underline">← Semua agen</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    {{-- ------------------------------------------------------------ pengaturan --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="text-sm font-semibold mb-3">Pengaturan</h2>

        <form method="POST" action="{{ route('crm.agen.update', $agen) }}" class="space-y-3">
            @csrf

            <div>
                <label class="block text-xs text-gray-500 mb-1">Nama</label>
                <input type="text" name="nama" value="{{ old('nama', $agen->nama) }}" required maxlength="80"
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">Persona &mdash; nada bicara</label>
                <textarea name="persona" rows="8" maxlength="8000"
                          class="w-full border rounded px-3 py-2 text-sm font-mono">{{ old('persona', $agen->persona) }}</textarea>
                {{-- Pemisahan ini yang paling sering salah dipahami, jadi
                     dinyatakan di tempat orang mengetiknya, bukan di dokumen. --}}
                <p class="mt-1 text-[11px] text-gray-500">
                    Ini mengatur <b>cara bicara</b>, bukan pagar. Larangan (diskon, ubah harga, janji tanggal jadi)
                    terkunci di kode dan <b>tidak bisa dilonggarkan dari sini</b> — aturan yang bisa terhapus
                    tak sengaja bukan pagar.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Model</label>
                    <select name="model" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Bawaan ({{ $agen->modelDipakai() }})</option>
                        @foreach($model as $m)
                            <option value="{{ $m }}" @selected($agen->model === $m)>{{ $m }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Maks putaran alat</label>
                    <input type="number" name="maks_giliran" min="1" max="12"
                           value="{{ old('maks_giliran', $agen->maks_giliran) }}" required
                           class="w-full border rounded px-3 py-2 text-sm">
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($agen->is_active) class="mt-0.5">
                <span>
                    Hidupkan agen ini
                    <span class="block text-[11px] text-gray-500">
                        Masih butuh saklar induk <span class="font-mono">CRM_AGEN_AKTIF</span> menyala.
                        Ronde ini belum ada jalur balas otomatis, jadi tanda ini belum berefek apa pun.
                    </span>
                </span>
            </label>

            <button class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">
                Simpan Pengaturan
            </button>
        </form>
    </div>

    {{-- ----------------------------------------------------------- pengetahuan --}}
    <div class="bg-white rounded shadow p-4">
        <h2 class="text-sm font-semibold mb-3">
            Pengetahuan
            @if($aktif)
                <span class="ml-1 text-xs font-normal text-emerald-700">versi {{ $aktif->versi }} dipakai</span>
            @else
                <span class="ml-1 text-xs font-normal text-gray-400">belum ada</span>
            @endif
        </h2>

        <form method="POST" action="{{ route('crm.agen.pengetahuan.simpan', $agen) }}"
              enctype="multipart/form-data" class="space-y-3">
            @csrf

            <div>
                <label class="block text-xs text-gray-500 mb-1">Unggah berkas (.md / .txt)</label>
                <input type="file" name="berkas" accept=".md,.txt,text/plain,text/markdown"
                       class="w-full border rounded px-3 py-2 text-sm">
                <p class="mt-1 text-[11px] text-gray-500">
                    Kalau berkas dipilih, isinya menang atas kotak di bawah.
                </p>
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">Atau sunting langsung</label>
                <textarea name="isi" rows="14"
                          class="w-full border rounded px-3 py-2 text-sm font-mono">{{ old('isi', $aktif?->isi) }}</textarea>
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">Catatan versi (opsional)</label>
                <input type="text" name="catatan" maxlength="255" placeholder="mis. tambah aturan ongkir gratis"
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>

            <button class="border border-emerald-600 text-emerald-700 hover:bg-emerald-50 px-3 py-1.5 rounded text-sm">
                Simpan sebagai Versi Baru
            </button>
        </form>

        @if($pengetahuan->isNotEmpty())
            <div class="mt-4 border-t pt-3">
                <div class="text-xs text-gray-500 mb-2">Riwayat versi</div>
                <div class="space-y-1 max-h-48 overflow-y-auto">
                    @foreach($pengetahuan as $v)
                        <div class="flex items-center gap-2 text-xs">
                            <span class="w-14 shrink-0 {{ $v->is_active ? 'font-semibold text-emerald-700' : 'text-gray-500' }}">
                                v{{ $v->versi }}
                            </span>
                            <span class="min-w-0 flex-1 truncate text-gray-600">
                                {{ $v->catatan ?: $v->sumber_berkas ?: '—' }}
                                <span class="text-gray-400">· {{ $v->created_at->format('d/m H:i') }}</span>
                                @if($v->penulis)<span class="text-gray-400">· {{ $v->penulis->name }}</span>@endif
                            </span>
                            @unless($v->is_active)
                                <form method="POST" action="{{ route('crm.agen.pengetahuan.pakai', [$agen, $v]) }}" class="shrink-0">
                                    @csrf
                                    <button class="text-emerald-700 hover:underline">Pakai</button>
                                </form>
                            @endunless
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ------------------------------------------------------------------- uji --}}
{{-- Tanpa layar ini, menyunting pengetahuan itu buta: hasilnya baru ketahuan
     dari pelanggan sungguhan, dan pada saat itu sudah terlambat. --}}
<div class="mt-4 bg-white rounded shadow p-4" x-data="ujiAgen(@js(route('crm.agen.uji', $agen)), @js(route('crm.percakapan.cari')))">
    <h2 class="text-sm font-semibold mb-1">Uji</h2>
    <p class="text-[11px] text-gray-500 mb-3">
        Menjalankan agen dengan pengetahuan yang berlaku sekarang. <b>Tidak ada pesan yang dikirim ke pelanggan.</b>
    </p>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="space-y-3">
            <div class="relative">
                <label class="block text-xs text-gray-500 mb-1">Uji di atas percakapan nyata (opsional)</label>
                <template x-if="! percakapan">
                    <input type="text" x-model="cari" @input.debounce.300ms="cariPercakapan()"
                           placeholder="Cari nama atau nomor…" autocomplete="off"
                           class="w-full border rounded px-3 py-2 text-sm">
                </template>
                <template x-if="percakapan">
                    <div class="flex items-center gap-2 border rounded bg-gray-50 px-3 py-2">
                        <div class="min-w-0">
                            <div class="text-sm truncate" x-text="percakapan.nama"></div>
                            <div class="text-[11px] text-gray-500 font-mono" x-text="percakapan.nomor"></div>
                        </div>
                        <button type="button" @click="percakapan = null; hasil = null"
                                class="ml-auto text-[11px] text-emerald-700 hover:underline">Ganti</button>
                    </div>
                </template>

                <div x-show="cari.trim() && ! percakapan" x-cloak
                     class="absolute z-10 mt-1 w-full bg-white border rounded-lg shadow-lg max-h-56 overflow-y-auto">
                    <template x-for="p in kandidat" :key="p.id">
                        <button type="button" @click="pilih(p)"
                                class="w-full text-left px-3 py-2 border-b last:border-0 hover:bg-gray-50">
                            <div class="text-sm truncate" x-text="p.nama"></div>
                            <div class="text-[11px] text-gray-500 font-mono" x-text="p.nomor"></div>
                        </button>
                    </template>
                    <p x-show="! kandidat.length" class="px-3 py-3 text-xs text-gray-500">Tidak ada yang cocok.</p>
                </div>

                <p class="mt-1 text-[11px] text-gray-500">
                    Kalau dipilih, agen ikut membaca riwayat chatnya — dan nadanya bisa dinilai
                    berdampingan dengan kalimat pelanggan yang sebenarnya.
                </p>
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">Pesan pelanggan</label>
                <textarea x-model="pesan" rows="3" maxlength="2000"
                          @keydown.enter.meta.prevent="jalankan()"
                          placeholder="mis. jam buka jam berapa kak?"
                          class="w-full border rounded px-3 py-2 text-sm"></textarea>
            </div>

            <button type="button" @click="jalankan()" :disabled="sibuk || ! pesan.trim()"
                    class="px-3 py-1.5 rounded text-sm text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-text="sibuk ? 'Menjalankan…' : 'Jalankan'"></span>
            </button>
        </div>

        <div>
            <div class="text-xs text-gray-500 mb-1">Balasan agen</div>

            <div x-show="! hasil" x-cloak class="rounded border border-dashed border-gray-300 px-3 py-6 text-center text-xs text-gray-400">
                Belum dijalankan.
            </div>

            <template x-if="hasil">
                <div class="space-y-2">
                    <div x-show="hasil.galat" x-cloak
                         class="rounded border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700"
                         x-text="hasil.galat"></div>

                    <div x-show="hasil.teks" class="rounded-lg bg-[#d9fdd3] px-3 py-2 text-sm whitespace-pre-wrap"
                         x-text="hasil.teks"></div>

                    <div x-show="hasil.status === 'dilempar'" x-cloak
                         class="text-[11px] text-amber-700">
                        Agen menyerahkan percakapan ini ke tim.
                    </div>

                    <template x-if="hasil.jejak && hasil.jejak.length">
                        <div class="rounded border border-gray-200 text-xs">
                            <div class="px-2 py-1 bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500">
                                Alat yang dipanggil
                            </div>
                            <template x-for="(j, i) in hasil.jejak" :key="i">
                                <div class="px-2 py-1.5 border-t border-gray-100">
                                    <div class="font-mono text-[11px] text-emerald-700" x-text="j.alat"></div>
                                    <div class="text-[11px] text-gray-500" x-text="JSON.stringify(j.input)"></div>
                                    <div class="mt-0.5 text-[11px] text-gray-600 whitespace-pre-wrap" x-text="j.hasil"></div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="text-[11px] text-gray-500" x-show="hasil.token" x-cloak>
                        <span x-text="hasil.biaya"></span> ·
                        <span x-text="hasil.token.masuk + ' masuk / ' + hasil.token.cache + ' cache / ' + hasil.token.keluar + ' keluar'"></span> ·
                        <span x-text="hasil.durasi + ' ms'"></span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

{{-- ------------------------------------------------------------ riwayat run --}}
@if($runs->isNotEmpty())
    <div class="mt-4 bg-white rounded shadow overflow-x-auto">
        <div class="px-3 py-2 text-sm font-semibold border-b">20 jalan terakhir</div>
        <table class="w-full text-xs">
            <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-3 py-2 w-28">Waktu</th>
                    <th class="text-left px-3 py-2 w-16">Mode</th>
                    <th class="text-left px-3 py-2 w-20">Status</th>
                    <th class="text-left px-3 py-2 w-14">Peng.</th>
                    <th class="text-left px-3 py-2">Masukan</th>
                    <th class="text-right px-3 py-2 w-20">Biaya</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach($runs as $r)
                    <tr>
                        <td class="px-3 py-2 whitespace-nowrap text-gray-500">{{ $r->created_at->format('d/m H:i') }}</td>
                        <td class="px-3 py-2">{{ $r->mode }}</td>
                        <td class="px-3 py-2">
                            <span class="px-1.5 py-0.5 rounded text-[10px]
                                {{ $r->status === 'sukses' ? 'bg-emerald-100 text-emerald-700'
                                   : ($r->status === 'dilempar' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-700') }}">
                                {{ $r->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-gray-500">{{ $r->pengetahuan?->versi ? 'v' . $r->pengetahuan->versi : '—' }}</td>
                        <td class="px-3 py-2 text-gray-600">{{ \Illuminate\Support\Str::limit($r->masukan, 80) }}</td>
                        <td class="px-3 py-2 text-right">Rp{{ number_format((float) $r->biaya_rp, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<script>
function ujiAgen(urlUji, urlCari) {
    return {
        urlUji, urlCari,
        pesan: '',
        cari: '',
        kandidat: [],
        percakapan: null,
        hasil: null,
        sibuk: false,

        async cariPercakapan() {
            const q = this.cari.trim();
            if (! q) { this.kandidat = []; return; }

            try {
                const r = await fetch(this.urlCari + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                });
                this.kandidat = r.ok ? ((await r.json()).hasil || []) : [];
            } catch (e) {
                this.kandidat = [];
            }
        },

        pilih(p) {
            this.percakapan = p;
            this.cari = '';
            this.kandidat = [];
        },

        async jalankan() {
            if (this.sibuk || ! this.pesan.trim()) return;

            this.sibuk = true;
            this.hasil = null;

            try {
                const r = await fetch(this.urlUji, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        pesan: this.pesan,
                        conversation_id: this.percakapan?.id ?? null,
                    }),
                });

                this.hasil = r.ok
                    ? await r.json()
                    : { teks: '', galat: 'Gagal menjalankan (HTTP ' + r.status + ').', jejak: [] };
            } catch (e) {
                this.hasil = { teks: '', galat: 'Jaringan bermasalah.', jejak: [] };
            } finally {
                this.sibuk = false;
            }
        },
    };
}
</script>
@endsection
