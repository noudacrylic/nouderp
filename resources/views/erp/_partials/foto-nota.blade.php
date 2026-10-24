{{-- Kartu "Foto Nota" di halaman detail dokumen.
     Param: $jenis (kunci FotoNotaService::JENIS), $doc (model dokumen).
     Unggah & hapus tetap bisa setelah dokumen di-POST — dokumen terkunci,
     notanya sering baru sempat difoto belakangan. --}}
@php
    $notaPrefix = \App\Services\FotoNotaService::routePrefix($jenis);
    $notaList   = $doc->fotoNota()->with('uploader:id,name')->orderBy('id')->get();
    $notaGambar = $notaList->filter->isImage()->values();
    // Kiriman PWA HP yang belum ditempel ke dokumen mana pun (Kotak Nota).
    // Dimuat ulang (fetch) tiap panel dibuka — foto baru dari HP muncul tanpa reload.
    $notaKotak  = \App\Services\FotoNotaService::kotakUntukPanel();
    // url ber-versi (?v=updated_at) → setelah diputar, browser tidak memakai cache lama.
    $notaFoto   = $notaGambar->map(fn ($a) => [
        'url'   => \App\Services\FotoNotaService::urlLihat($jenis, $a),
        'putar' => route($notaPrefix . '.nota.putar', [$doc->id, $a->id]),
    ])->values();
@endphp
<div class="bg-white rounded shadow p-4 mb-4"
     x-data="{
        lihat: null, hp: false, kotak: @js($notaKotak), memuatHp: false, memutar: false,
        daftar: @js($notaFoto),
        async muatHp() {
            this.memuatHp = true;
            try {
                const r = await fetch(@js(route('nota-kotak.daftar')), { headers: { Accept: 'application/json' } });
                if (r.ok) this.kotak = await r.json();
            } catch (e) {} finally { this.memuatHp = false; }
        },
        async putar(i, arah) {
            if (this.memutar) return;
            this.memutar = true;
            try {
                const r = await fetch(this.daftar[i].putar, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                    body: JSON.stringify({ arah }),
                });
                const d = await r.json().catch(() => ({}));
                if (r.ok && d.url) this.daftar[i].url = d.url;
                else alert(d.pesan || 'Foto gagal diputar.');
            } catch (e) { alert('Foto gagal diputar. Coba lagi.'); }
            finally { this.memutar = false; }
        },
     }"
     @focus.window="hp && muatHp()"
     @keydown.escape.window="lihat = null"
     @keydown.arrow-right.window="if (lihat !== null) lihat = (lihat + 1) % daftar.length"
     @keydown.arrow-left.window="if (lihat !== null) lihat = (lihat - 1 + daftar.length) % daftar.length">

    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <h3 class="font-semibold">
            📎 Foto Nota
            <span class="text-xs font-normal text-gray-500">({{ $notaList->count() }})</span>
        </h3>

        <form method="POST" action="{{ route($notaPrefix . '.nota.store', $doc->id) }}" enctype="multipart/form-data"
              class="flex flex-wrap items-center gap-2">
            @csrf
            {{-- capture → di HP langsung membuka kamera; di PC diabaikan (jadi pilih file). --}}
            <label data-foto-nota-tombol
                   class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm font-semibold">
                📷 Foto nota
                <input type="file" name="nota[]" accept="image/*" capture="environment" multiple
                       data-foto-nota data-auto-submit class="hidden">
            </label>
            <label data-foto-nota-tombol
                   class="cursor-pointer border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded text-sm">
                🖼️ Pilih file
                <input type="file" name="nota[]" accept="image/*,application/pdf" multiple
                       data-foto-nota data-auto-submit class="hidden">
            </label>
            <button type="button" @click="hp = !hp; if (hp) muatHp()"
                    class="border border-teal-300 hover:bg-teal-50 text-teal-700 px-3 py-2 rounded text-sm font-semibold">
                📱 Ambil dari HP
                <span x-show="kotak.length" x-text="kotak.length" @if ($notaKotak->isEmpty()) x-cloak @endif
                      class="ml-0.5 bg-teal-600 text-white rounded-full px-1.5 text-[11px]">{{ $notaKotak->count() }}</span>
            </button>
            <span data-foto-nota-info class="text-xs text-gray-500"></span>
        </form>
    </div>

    {{-- Kotak Nota: foto dari menu Nota di PWA HP. Klik "Tempel" → pindah ke dokumen ini. --}}
    <div x-show="hp" x-cloak class="mb-4 border border-teal-200 bg-teal-50/50 rounded p-3">
        <div class="flex items-center justify-between mb-2">
            <p class="text-sm font-semibold text-teal-800">Foto dari HP yang belum ditempel</p>
            <a href="{{ route('nota-kotak.index') }}" class="text-xs text-teal-700 underline">Kelola Kotak Nota</a>
        </div>
        <p x-show="!kotak.length" class="text-sm text-gray-500">
            <span x-show="memuatHp">Memuat…</span>
            <span x-show="!memuatHp">Kosong. Foto nota dari HP lewat PWA (<b>/cs</b> → menu <b>Nota</b>), lalu tempel di sini.</span>
        </p>
        <div x-show="kotak.length" class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3">
            <template x-for="k in kotak" :key="k.id">
                <div class="bg-white border border-gray-200 rounded overflow-hidden flex flex-col">
                    <a :href="k.url" target="_blank" rel="noopener" class="block bg-gray-50">
                        <template x-if="k.gambar">
                            <img :src="k.url" alt="Foto nota" loading="lazy" class="w-full h-28 object-cover">
                        </template>
                        <template x-if="!k.gambar">
                            <span class="flex items-center justify-center h-28 text-3xl">📄</span>
                        </template>
                    </a>
                    <div class="px-1.5 py-1 text-[10px] text-gray-500 leading-tight flex-1">
                        <span x-text="k.waktu"></span>
                        <div x-show="k.catatan" x-text="k.catatan" :title="k.catatan"
                             class="text-[11px] text-gray-800 font-semibold truncate"></div>
                    </div>
                    <form method="POST" action="{{ route($notaPrefix . '.nota.tempel', $doc->id) }}">
                        @csrf
                        <input type="hidden" name="lampiran_id" :value="k.id">
                        <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white text-xs font-semibold py-1.5">
                            Tempel ke sini
                        </button>
                    </form>
                </div>
            </template>
        </div>
    </div>

    @if ($notaList->isEmpty())
        <p class="text-sm text-gray-400">
            Belum ada foto nota. Foto nota / bukti transfer supaya tersimpan bersama dokumen ini —
            nota kertasnya tidak perlu disimpan lagi.
        </p>
    @else
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
            @foreach ($notaList as $a)
                @php
                    $url = \App\Services\FotoNotaService::urlLihat($jenis, $a);
                    $i   = $notaGambar->search(fn ($g) => $g->id === $a->id);
                @endphp
                <div class="relative group border border-gray-200 rounded overflow-hidden bg-gray-50">
                    @if ($a->isImage())
                        <button type="button" class="block w-full" @click="lihat = {{ $i }}">
                            <img src="{{ $url }}" :src="daftar[{{ $i }}].url" alt="Foto nota" loading="lazy" class="w-full h-28 object-cover">
                        </button>
                        <div class="absolute top-1 left-1 flex gap-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition">
                            <button type="button" title="Putar ke kiri" @click="putar({{ $i }}, 'kiri')" :disabled="memutar"
                                    class="bg-white/90 hover:bg-gray-800 hover:text-white text-gray-700 rounded w-7 h-7 text-sm shadow">⟲</button>
                            <button type="button" title="Putar ke kanan" @click="putar({{ $i }}, 'kanan')" :disabled="memutar"
                                    class="bg-white/90 hover:bg-gray-800 hover:text-white text-gray-700 rounded w-7 h-7 text-sm shadow">⟳</button>
                        </div>
                    @else
                        <a href="{{ $url }}" target="_blank" rel="noopener"
                           class="flex flex-col items-center justify-center h-28 text-gray-600 text-xs p-2 text-center">
                            <span class="text-3xl">📄</span>
                            <span class="truncate w-full">{{ $a->original_name }}</span>
                        </a>
                    @endif
                    <div class="px-1.5 py-1 text-[10px] text-gray-500 leading-tight bg-white">
                        {{ $a->created_at?->format('d/m/y H:i') }}{{ $a->uploader ? ' · ' . $a->uploader->name : '' }}
                    </div>
                    <form method="POST" action="{{ route($notaPrefix . '.nota.destroy', [$doc->id, $a->id]) }}"
                          onsubmit="return confirm('Hapus foto nota ini? (diarsipkan, masih bisa dipulihkan admin)')"
                          class="absolute top-1 right-1 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition">
                        @csrf
                        @method('DELETE')
                        <button type="submit" title="Hapus"
                                class="bg-white/90 hover:bg-red-600 hover:text-white text-red-600 rounded w-7 h-7 text-sm shadow">✕</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Pratinjau besar --}}
    <template x-teleport="body">
        <div x-show="lihat !== null" x-cloak @click.self="lihat = null"
             class="fixed inset-0 z-[9998] bg-black/85 flex items-center justify-center p-4">
            <img :src="lihat !== null ? daftar[lihat].url : ''" alt="Foto nota"
                 class="max-w-full max-h-full object-contain rounded shadow-2xl" :class="memutar && 'opacity-50'">
            <button type="button" @click="lihat = null"
                    class="absolute top-4 right-4 text-white text-3xl leading-none">&times;</button>
            <template x-if="daftar.length > 1">
                <div>
                    <button type="button" @click="lihat = (lihat - 1 + daftar.length) % daftar.length"
                            class="absolute left-3 top-1/2 -translate-y-1/2 text-white text-4xl px-2">&lsaquo;</button>
                    <button type="button" @click="lihat = (lihat + 1) % daftar.length"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-white text-4xl px-2">&rsaquo;</button>
                </div>
            </template>
            <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
                <button type="button" @click="putar(lihat, 'kiri')" :disabled="memutar"
                        class="bg-white/15 hover:bg-white/30 text-white rounded px-3 py-1.5 text-sm">⟲ Putar kiri</button>
                <button type="button" @click="putar(lihat, 'kanan')" :disabled="memutar"
                        class="bg-white/15 hover:bg-white/30 text-white rounded px-3 py-1.5 text-sm">Putar kanan ⟳</button>
            </div>
            <a :href="lihat !== null ? daftar[lihat].url : '#'" target="_blank" rel="noopener"
               class="absolute bottom-4 right-4 text-white/80 hover:text-white text-sm underline">Buka ukuran asli</a>
        </div>
    </template>
</div>

@once
    @push('scripts')
        <script src="{{ asset('js/foto-nota.js') }}?v={{ @filemtime(public_path('js/foto-nota.js')) }}"></script>
    @endpush
@endonce
