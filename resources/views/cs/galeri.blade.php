@extends('layouts.cs')

@section('title', 'Galeri — NOUD')

@php
    $item = array_map(fn ($b) => $b + [
        'thumb'  => route('cs.galeri.thumb', ['p' => $b['path'], 'v' => $b['waktu']]),
        'url'    => route('cs.galeri.berkas', ['p' => $b['path'], 'v' => $b['waktu']]),
        'unduh'  => route('cs.galeri.berkas', ['p' => $b['path'], 'unduh' => 1]),
        'tanggal' => \Illuminate\Support\Carbon::createFromTimestamp($b['waktu'])->timezone(config('app.timezone'))->translatedFormat('d M Y, H:i'),
    ], $berkas);
    $namaFolder = end($remah)['nama'];
@endphp

@section('topbar')
    <div class="px-4 pt-3 pb-2">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-extrabold truncate">{{ $path === '' ? 'Galeri' : $namaFolder }}</h1>
            <span class="text-[11px] text-teal-100 shrink-0">{{ count($folder) }} folder · {{ count($berkas) }} berkas</span>
        </div>
        {{-- Remah roti: tiap tingkat bisa ditekan untuk naik. --}}
        <nav class="mt-1 flex items-center gap-1 text-xs text-teal-100 overflow-x-auto whitespace-nowrap">
            @foreach ($remah as $r)
                @if (! $loop->first)<span class="opacity-60">›</span>@endif
                @if ($loop->last)
                    <span class="font-semibold text-white">{{ $r['nama'] }}</span>
                @else
                    <a href="{{ route('cs.galeri', $r['path'] === '' ? [] : ['p' => $r['path']]) }}" class="underline-offset-2 active:underline">{{ $r['nama'] }}</a>
                @endif
            @endforeach
        </nav>
    </div>
@endsection

@section('content')
<div class="p-3 space-y-3"
     x-data="galeriApp({
        folder: @js($path),
        urlUnggah: @js(route('cs.galeri.unggah')),
        urlFolder: @js(route('cs.galeri.folder')),
        so: {{ $soAktif['id'] ?? 'null' }},
        potongan: {{ $potongan }},
        maksMb: {{ $maksMb }},
     })">

    @unless ($tersambung)
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-3 text-sm">
            <b>NAS tidak tersambung.</b> Galeri belum bisa dibuka atau diisi. Hubungi admin untuk memeriksa
            koneksi server ke Synology.
        </div>
    @else

    {{-- Sedang memotret untuk satu pesanan (tombol "Foto produk" di Meja Kerja). --}}
    @if ($soAktif)
        <div class="bg-amber-50 border border-amber-300 rounded-2xl p-3 text-sm text-amber-900 space-y-1.5">
            <div class="flex items-start justify-between gap-2">
                <p class="font-bold">📌 Foto untuk {{ $soAktif['nomor'] }}</p>
                <a href="{{ route('cs.galeri', ['so' => 0] + ($path === '' ? [] : ['p' => $path])) }}" class="shrink-0 text-xs font-semibold underline">Selesai</a>
            </div>
            @if ($soAktif['barang'])<p class="text-xs">{{ $soAktif['barang'] }}</p>@endif
            <p class="text-[11px]">Buka folder lini produknya, lalu folder <b>Custom</b> di dalamnya (buat bila belum ada). Produk yang belum punya lini: folder <b>Custom</b> di akar. Unggahan di sini otomatis tertaut ke pesanan ini.</p>
            <p class="text-[11px] font-semibold">{{ $soAktif['jumlah'] }} foto sudah tertaut.</p>
        </div>
    @endif

    {{-- Tombol unggah. Kamera foto & video dipisah: satu input dengan
         accept image+video membuat sebagian HP langsung membuka kamera foto. --}}
    <div class="grid grid-cols-3 gap-2">
        <label class="flex flex-col items-center gap-1 rounded-2xl bg-teal-700 active:bg-teal-800 text-white py-3 cursor-pointer select-none">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span class="text-xs font-bold">Foto</span>
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="pilihBerkas($event, 'kamera')">
        </label>
        <label class="flex flex-col items-center gap-1 rounded-2xl bg-teal-700 active:bg-teal-800 text-white py-3 cursor-pointer select-none">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            <span class="text-xs font-bold">Video</span>
            <input type="file" accept="video/*" capture="environment" class="hidden" @change="pilihBerkas($event, 'kamera')">
        </label>
        <label class="flex flex-col items-center gap-1 rounded-2xl bg-white border border-teal-200 text-teal-700 py-3 cursor-pointer select-none">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span class="text-xs font-bold">Dari HP</span>
            <input type="file" accept="image/*,video/*" multiple class="hidden" @change="pilihBerkas($event, 'galeri')">
        </label>
    </div>
    <p class="text-[11px] text-slate-500 px-1">Masuk ke folder <b>{{ $namaFolder }}</b> dengan kualitas asli.</p>

    {{-- Antrian unggah --}}
    <template x-if="antrian.length">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 space-y-2">
            <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-slate-700" x-text="sibuk ? 'Mengunggah… (' + sisaAntre() + ' lagi)' : 'Unggahan'"></span>
                <button type="button" x-show="!sibuk && antrian.some(x => x.status === 'gagal')" @click="ulangiGagal()"
                        class="font-semibold text-teal-700">Ulangi yang gagal</button>
                <button type="button" x-show="!sibuk && antrian.some(x => x.status === 'selesai')" @click="location.reload()"
                        class="font-semibold text-teal-700">Muat ulang</button>
            </div>
            <p x-show="sibuk" class="text-[11px] text-amber-700">Jangan tutup aplikasi sampai selesai.</p>
            <template x-for="it in antrian" :key="it.id">
                <div>
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="truncate text-slate-700" x-text="it.nama"></span>
                        <span class="shrink-0 font-semibold"
                              :class="{ 'text-emerald-600': it.status === 'selesai', 'text-red-600': it.status === 'gagal', 'text-slate-500': it.status === 'antre' || it.status === 'jalan' }"
                              x-text="it.status === 'selesai' ? '✓' : (it.status === 'gagal' ? 'Gagal' : (it.status === 'antre' ? 'antre' : it.persen + '%'))"></span>
                    </div>
                    <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1">
                        <div class="h-full transition-all" :class="it.status === 'gagal' ? 'bg-red-400' : 'bg-teal-600'" :style="'width:' + it.persen + '%'"></div>
                    </div>
                    <p x-show="it.pesan" class="text-[11px] mt-0.5" :class="it.status === 'gagal' ? 'text-red-600' : 'text-slate-500'" x-text="it.pesan"></p>
                </div>
            </template>
        </div>
    </template>

    {{-- Folder --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm divide-y divide-slate-100">
        @foreach ($folder as $f)
            <div class="flex items-center">
                <a href="{{ route('cs.galeri', ['p' => $f['path']]) }}" class="flex-1 min-w-0 flex items-center gap-3 px-3 py-3 active:bg-slate-50">
                    <span class="text-2xl leading-none">📁</span>
                    <span class="truncate text-sm font-semibold text-slate-800">{{ $f['nama'] }}</span>
                </a>
                <button type="button" class="px-4 py-3 text-slate-400 text-lg"
                        @click="menu = @js($f)">⋯</button>
            </div>
        @endforeach
        <button type="button" @click="formNama = { judul: 'Folder baru', aksi: @js(route('cs.galeri.folder.store')), p: @js($path), nama: '' }"
                class="w-full flex items-center gap-3 px-3 py-3 text-sm font-semibold text-teal-700 active:bg-slate-50">
            <span class="text-xl leading-none w-6 text-center">＋</span> Folder baru
        </button>
    </div>

    {{-- Berkas --}}
    @if (count($item))
        <div class="grid grid-cols-3 gap-1.5">
            @foreach ($item as $i => $b)
                <button type="button" @click="lihat = @js($b)"
                        class="relative aspect-square rounded-xl overflow-hidden bg-slate-200 flex items-center justify-center">
                    @if ($b['jenis'] === 'lain')
                        <span class="text-3xl">📄</span>
                    @else
                        <img src="{{ $b['thumb'] }}" alt="" loading="lazy" class="w-full h-full object-cover"
                             onerror="this.replaceWith(Object.assign(document.createElement('span'), { className: 'text-3xl', textContent: @js($b['jenis'] === 'video' ? '🎬' : '🖼') }))">
                    @endif
                    @if ($b['jenis'] === 'video')
                        <span class="absolute bottom-1 left-1 bg-black/60 text-white text-[10px] font-bold rounded px-1.5 py-0.5">▶ VIDEO</span>
                    @endif
                    @if ($b['jenis'] !== 'gambar')
                        <span class="absolute top-0 inset-x-0 bg-gradient-to-b from-black/50 to-transparent text-white text-[10px] px-1.5 pt-1 pb-3 truncate text-left">{{ $b['nama'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>
    @elseif (! count($folder))
        <p class="text-center text-sm text-slate-400 py-8">Folder ini masih kosong.</p>
    @endif

    {{-- ───── Penampil berkas ───── --}}
    <template x-if="lihat">
        <div class="fixed inset-0 z-40 bg-black flex flex-col max-w-md mx-auto lapis-layar">
            <div class="flex items-center gap-2 px-3 py-2.5 text-white">
                <button type="button" @click="lihat = null" class="text-2xl px-1 leading-none">✕</button>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold truncate" x-text="lihat.nama"></p>
                    <p class="text-[11px] text-white/60" x-text="lihat.tanggal + ' · ' + formatUkuran(lihat.ukuran)"></p>
                </div>
            </div>
            <div class="flex-1 min-h-0 flex items-center justify-center">
                <template x-if="lihat.jenis === 'gambar'">
                    <img :src="lihat.url" alt="" class="max-w-full max-h-full object-contain">
                </template>
                <template x-if="lihat.jenis === 'video'">
                    <video :src="lihat.url" controls playsinline preload="metadata" class="max-w-full max-h-full"></video>
                </template>
                <template x-if="lihat.jenis === 'lain'">
                    <p class="text-white/70 text-sm px-6 text-center">Berkas ini tidak bisa ditampilkan di HP. Unduh untuk membukanya.</p>
                </template>
            </div>
            <div class="grid {{ $soAktif ? 'grid-cols-5' : 'grid-cols-4' }} text-white text-[11px] font-semibold border-t border-white/10" style="padding-bottom: env(safe-area-inset-bottom, 0px)">
                @if ($soAktif)
                    <form method="POST" action="{{ route('cs.galeri.tautkan') }}">
                        @csrf
                        <input type="hidden" name="p" :value="lihat.path">
                        <input type="hidden" name="kembali" value="{{ $path }}">
                        <button type="submit" class="w-full flex flex-col items-center gap-1 py-3 text-amber-300 active:bg-white/10"><span class="text-lg">📌</span>Tautkan</button>
                    </form>
                @endif
                <a :href="lihat.unduh" class="flex flex-col items-center gap-1 py-3 active:bg-white/10"><span class="text-lg">⬇</span>Unduh</a>
                <button type="button" class="flex flex-col items-center gap-1 py-3 active:bg-white/10"
                        @click="formNama = { judul: 'Ganti nama', aksi: @js(route('cs.galeri.ganti-nama')), p: lihat.path, nama: lihat.nama.replace(/\.[^.]+$/, '') }; lihat = null"><span class="text-lg">✎</span>Ganti nama</button>
                <button type="button" class="flex flex-col items-center gap-1 py-3 active:bg-white/10"
                        @click="const p = lihat.path; lihat = null; bukaPilih(p)"><span class="text-lg">↪</span>Pindah</button>
                <form method="POST" action="{{ route('cs.galeri.hapus') }}" onsubmit="return confirm('Hapus berkas ini? Masih bisa dipulihkan dari folder #recycle di PC.')">
                    @csrf
                    <input type="hidden" name="p" :value="lihat.path">
                    <input type="hidden" name="kembali" value="{{ $path }}">
                    <button type="submit" class="w-full flex flex-col items-center gap-1 py-3 text-red-300 active:bg-white/10"><span class="text-lg">🗑</span>Hapus</button>
                </form>
            </div>
        </div>
    </template>

    {{-- ───── Menu folder (⋯) ───── --}}
    <template x-if="menu">
        <div class="fixed inset-0 z-40 bg-black/40 flex items-end max-w-md mx-auto lapis-layar" @click.self="menu = null">
            <div class="w-full bg-white rounded-t-2xl p-2 pb-4" style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))">
                <p class="px-3 py-2 text-sm font-bold text-slate-700 truncate" x-text="'📁 ' + menu.nama"></p>
                <button type="button" class="w-full text-left px-3 py-3 text-sm rounded-xl active:bg-slate-100"
                        @click="formNama = { judul: 'Ganti nama folder', aksi: @js(route('cs.galeri.ganti-nama')), p: menu.path, nama: menu.nama }; menu = null">✎ Ganti nama</button>
                <button type="button" class="w-full text-left px-3 py-3 text-sm rounded-xl active:bg-slate-100"
                        @click="bukaPilih(menu.path)">↪ Pindah ke folder lain</button>
                <form method="POST" action="{{ route('cs.galeri.hapus') }}" onsubmit="return confirm('Hapus folder ini BESERTA seluruh isinya? Masih bisa dipulihkan dari folder #recycle di PC.')">
                    @csrf
                    <input type="hidden" name="p" :value="menu.path">
                    <input type="hidden" name="kembali" value="{{ $path }}">
                    <button type="submit" class="w-full text-left px-3 py-3 text-sm text-red-600 rounded-xl active:bg-slate-100">🗑 Hapus folder</button>
                </form>
            </div>
        </div>
    </template>

    {{-- ───── Isi nama (folder baru / ganti nama) ───── --}}
    <template x-if="formNama">
        <div class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4 max-w-md mx-auto lapis-layar" @click.self="formNama = null">
            <form method="POST" :action="formNama.aksi" class="w-full bg-white rounded-2xl p-4 space-y-3">
                @csrf
                <input type="hidden" name="p" :value="formNama.p">
                <input type="hidden" name="kembali" value="{{ $path }}">
                <p class="font-bold text-slate-800" x-text="formNama.judul"></p>
                <input type="text" name="nama" x-model="formNama.nama" maxlength="150" required autocomplete="off"
                       x-init="$nextTick(() => $el.focus())"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-200">
                <div class="flex justify-end gap-2">
                    <button type="button" @click="formNama = null" class="px-4 py-2 text-sm font-semibold text-slate-500">Batal</button>
                    <button type="submit" class="px-4 py-2 text-sm font-bold text-white bg-teal-700 rounded-xl">Simpan</button>
                </div>
            </form>
        </div>
    </template>

    {{-- ───── Pilih folder tujuan (pindah) ───── --}}
    <template x-if="pilih">
        <div class="fixed inset-0 z-50 bg-black/40 flex items-end max-w-md mx-auto lapis-layar" @click.self="pilih = null">
            <form method="POST" action="{{ route('cs.galeri.pindah') }}" class="w-full bg-white rounded-t-2xl flex flex-col max-h-[80%]">
                @csrf
                <input type="hidden" name="p" :value="pilih.p">
                <input type="hidden" name="tujuan" :value="pilih.path">
                <input type="hidden" name="kembali" value="{{ $path }}">
                <div class="px-4 pt-4 pb-2">
                    <p class="font-bold text-slate-800">Pindah ke…</p>
                    <div class="mt-1 flex items-center gap-1 text-xs text-slate-500 overflow-x-auto whitespace-nowrap">
                        <template x-for="(r, i) in pilih.remah" :key="r.path">
                            <span>
                                <span x-show="i > 0" class="opacity-60">›</span>
                                <button type="button" @click="muatPilih(r.path)" :class="i === pilih.remah.length - 1 ? 'font-bold text-slate-800' : 'text-teal-700'" x-text="r.nama"></button>
                            </span>
                        </template>
                    </div>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto divide-y divide-slate-100 border-y border-slate-100">
                    <p x-show="pilih.muat" class="px-4 py-3 text-sm text-slate-400">Memuat…</p>
                    <template x-for="f in pilih.folder" :key="f.path">
                        <button type="button" @click="muatPilih(f.path)" class="w-full flex items-center gap-3 px-4 py-3 text-sm text-left active:bg-slate-50">
                            <span class="text-xl leading-none">📁</span><span class="truncate" x-text="f.nama"></span>
                        </button>
                    </template>
                    <p x-show="!pilih.muat && !pilih.folder.length" class="px-4 py-3 text-sm text-slate-400">Tidak ada subfolder.</p>
                </div>
                <div class="flex justify-end gap-2 p-3" style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px))">
                    <button type="button" @click="pilih = null" class="px-4 py-2 text-sm font-semibold text-slate-500">Batal</button>
                    <button type="submit" :disabled="pilih.muat" class="px-4 py-2 text-sm font-bold text-white bg-teal-700 rounded-xl disabled:opacity-50">Pindah ke sini</button>
                </div>
            </form>
        </div>
    </template>

    @endunless
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/galeri.js') }}?v={{ @filemtime(public_path('js/galeri.js')) }}"></script>
@endpush
