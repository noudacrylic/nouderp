@extends('layouts.cs')

@section('title', 'Porto — NOUD')

@section('topbar')
    <div class="px-4 pt-3 pb-2">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-extrabold">Upload Porto</h1>
            <span class="text-[11px] text-teal-100 shrink-0">{{ count($items) }} menunggu</span>
        </div>
        <p class="mt-1 text-xs text-teal-100">Foto produk jadi → lampirkan dari kartu Meja Kerja di laptop.</p>
    </div>
@endsection

@section('content')
<div class="p-3 space-y-3"
     x-data="galeriApp({
        folder: '',
        urlUnggah: @js(route('cs.porto.unggah')),
        urlFolder: '',
        so: null,
        potongan: {{ $potongan }},
        maksMb: {{ $maksMb }},
     })">

    {{-- Dari galeri HP: foto kamera bawaan biasanya lebih bagus daripada kamera di peramban.
         Dikirim utuh (tanpa kompresi), dipotong-potong per bagian. --}}
    <label class="flex items-center justify-center gap-2 rounded-2xl bg-teal-700 active:bg-teal-800 text-white py-4 cursor-pointer select-none">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span class="text-sm font-bold">Pilih foto / video dari galeri HP</span>
        <input type="file" accept="image/*,video/*" multiple class="hidden" @change="pilihBerkas($event, 'galeri')">
    </label>
    <p class="text-[11px] text-slate-500 px-1">Dikirim dengan kualitas asli. Belum dilampirkan dalam {{ $hari }} hari → dihapus otomatis.</p>

    {{-- Antrian unggah --}}
    <template x-if="antrian.length">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 space-y-2">
            <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-slate-700" x-text="sibuk ? 'Mengunggah… (' + sisaAntre() + ' lagi)' : 'Unggahan'"></span>
                <button type="button" x-show="!sibuk && antrian.some(x => x.status === 'gagal')" @click="ulangiGagal()" class="font-semibold text-teal-700">Ulangi yang gagal</button>
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

    {{-- Isi kotak: menunggu dilampirkan --}}
    @if (count($items))
        <div class="grid grid-cols-3 gap-1.5">
            @foreach ($items as $k)
                <div class="relative aspect-square rounded-xl overflow-hidden bg-slate-200">
                    <a href="{{ route('cs.porto.berkas', $k->id) }}" target="_blank" rel="noopener" class="block w-full h-full">
                        <img src="{{ route('cs.porto.thumb', $k->id) }}" alt="" loading="lazy" class="w-full h-full object-cover"
                             onerror="this.replaceWith(Object.assign(document.createElement('span'), { className: 'w-full h-full flex items-center justify-center text-3xl', textContent: @js($k->jenis === 'video' ? '🎬' : '🖼') }))">
                    </a>
                    @if ($k->jenis === 'video')
                        <span class="absolute bottom-1 left-1 bg-black/60 text-white text-[10px] font-bold rounded px-1.5 py-0.5">▶ VIDEO</span>
                    @endif
                    <span class="absolute top-0 inset-x-0 bg-gradient-to-b from-black/50 to-transparent text-white text-[10px] px-1.5 pt-1 pb-3 truncate">
                        {{ $k->user?->name ?? '—' }} · {{ $k->created_at->timezone(config('app.timezone'))->format('d/m H:i') }}
                    </span>
                    <form method="POST" action="{{ route('cs.porto.hapus', $k->id) }}" onsubmit="return confirm('Hapus dari Kotak Porto?')" class="absolute bottom-1 right-1">
                        @csrf @method('DELETE')
                        <button class="bg-white/90 text-red-600 rounded-full w-7 h-7 text-sm leading-none shadow">🗑</button>
                    </form>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-center text-sm text-slate-400 py-8">Kotak kosong. Foto yang diunggah di sini menunggu dilampirkan dari kartu Meja Kerja.</p>
    @endif
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/galeri.js') }}?v={{ @filemtime(public_path('js/galeri.js')) }}"></script>
@endpush
