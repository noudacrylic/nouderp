{{-- Kartu "Foto Nota" di halaman detail dokumen.
     Param: $jenis (kunci FotoNotaService::JENIS), $doc (model dokumen).
     Unggah & hapus tetap bisa setelah dokumen di-POST — dokumen terkunci,
     notanya sering baru sempat difoto belakangan. --}}
@php
    $notaPrefix = \App\Services\FotoNotaService::routePrefix($jenis);
    $notaList   = $doc->fotoNota()->with('uploader:id,name')->orderBy('id')->get();
    $notaGambar = $notaList->filter->isImage()->values();
@endphp
<div class="bg-white rounded shadow p-4 mb-4"
     x-data="{ lihat: null, daftar: @js($notaGambar->map(fn ($a) => route($notaPrefix . '.nota.lihat', [$doc->id, $a->id]))->values()) }"
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
            <span data-foto-nota-info class="text-xs text-gray-500"></span>
        </form>
    </div>

    @if ($notaList->isEmpty())
        <p class="text-sm text-gray-400">
            Belum ada foto nota. Foto nota / bukti transfer supaya tersimpan bersama dokumen ini —
            nota kertasnya tidak perlu disimpan lagi.
        </p>
    @else
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
            @foreach ($notaList as $a)
                @php $url = route($notaPrefix . '.nota.lihat', [$doc->id, $a->id]); @endphp
                <div class="relative group border border-gray-200 rounded overflow-hidden bg-gray-50">
                    @if ($a->isImage())
                        <button type="button" class="block w-full"
                                @click="lihat = {{ $notaGambar->search(fn ($g) => $g->id === $a->id) }}">
                            <img src="{{ $url }}" alt="Foto nota" loading="lazy" class="w-full h-28 object-cover">
                        </button>
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
            <img :src="lihat !== null ? daftar[lihat] : ''" alt="Foto nota"
                 class="max-w-full max-h-full object-contain rounded shadow-2xl">
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
            <a :href="lihat !== null ? daftar[lihat] : '#'" target="_blank" rel="noopener"
               class="absolute bottom-4 right-4 text-white/80 hover:text-white text-sm underline">Buka ukuran asli</a>
        </div>
    </template>
</div>

@once
    @push('scripts')
        <script src="{{ asset('js/foto-nota.js') }}?v={{ @filemtime(public_path('js/foto-nota.js')) }}"></script>
    @endpush
@endonce
