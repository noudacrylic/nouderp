@extends('layouts.cs')

@section('title', 'Foto Nota — NOUD')

@section('topbar')
    <div class="px-4 py-3.5 flex items-center justify-between">
        <h1 class="text-lg font-extrabold">Foto Nota</h1>
        @if ($menunggu > 0)
            <span class="text-xs font-semibold bg-white/15 rounded-full px-2.5 py-1">{{ $menunggu }} belum ditempel</span>
        @endif
    </div>
@endsection

@section('content')
<div class="p-4 space-y-4">

    {{-- Satu ketukan: catatan (opsional) diisi dulu, lalu jepret — form
         langsung terkirim begitu foto selesai diperkecil (foto-nota.js). --}}
    <form method="POST" action="{{ route('cs.nota.store') }}" enctype="multipart/form-data"
          class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 space-y-3">
        @csrf
        <input type="text" name="catatan" maxlength="190" autocomplete="off"
               placeholder="Catatan (opsional), mis. Toko Lem 25rb"
               class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-200">

        <label data-foto-nota-tombol
               class="flex flex-col items-center justify-center gap-1.5 w-full rounded-2xl bg-teal-700 active:bg-teal-800 text-white py-7 cursor-pointer select-none">
            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span class="text-base font-extrabold">Foto nota</span>
            <span class="text-[11px] text-teal-100">Langsung terkirim ke ERP</span>
            <input type="file" name="nota[]" accept="image/*" capture="environment" multiple
                   data-foto-nota data-auto-submit class="hidden">
        </label>

        <label data-foto-nota-tombol
               class="block text-center text-sm font-semibold text-teal-700 border border-teal-200 rounded-xl py-2.5 cursor-pointer">
            Pilih dari galeri / PDF
            <input type="file" name="nota[]" accept="image/*,application/pdf" multiple
                   data-foto-nota data-auto-submit class="hidden">
        </label>

        <p data-foto-nota-info class="text-center text-xs text-slate-500"></p>
        @error('nota') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        @error('nota.*') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
    </form>

    <p class="text-xs text-slate-500 px-1 leading-relaxed">
        Foto masuk ke <b>Kotak Nota</b> di ERP. Di PC, buka faktur / pembayaran / pengeluarannya lalu
        tekan <b>📱 Ambil dari HP</b> di kartu Foto Nota untuk menempelkannya.
    </p>

    <div>
        <h2 class="text-sm font-bold text-slate-700 mb-2 px-1">Kiriman saya (14 hari)</h2>
        @forelse ($milikku as $a)
            @php $tempel = \App\Services\FotoNotaService::labelDokumen($a); @endphp
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-2.5 mb-2 flex items-center gap-3">
                <a href="{{ route('cs.nota.gambar', $a->id) }}" target="_blank" rel="noopener"
                   class="w-16 h-16 shrink-0 rounded-xl overflow-hidden bg-slate-100 flex items-center justify-center">
                    @if ($a->isImage())
                        <img src="{{ route('cs.nota.gambar', $a->id) }}" alt="Nota" loading="lazy" class="w-full h-full object-cover">
                    @else
                        <span class="text-2xl">📄</span>
                    @endif
                </a>
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-slate-500">{{ $a->created_at->translatedFormat('d M, H:i') }}</p>
                    @if ($a->catatan)
                        <p class="text-sm font-semibold text-slate-800 truncate">{{ $a->catatan }}</p>
                    @endif
                    @if ($tempel)
                        <p class="text-xs font-semibold text-emerald-700 truncate">✓ {{ $tempel }}</p>
                    @else
                        <p class="text-xs font-semibold text-amber-600">Menunggu ditempel</p>
                    @endif
                </div>
                @unless ($tempel)
                    <form method="POST" action="{{ route('cs.nota.destroy', $a->id) }}"
                          onsubmit="return confirm('Batalkan foto ini?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-slate-400 active:text-red-600 text-xl px-2" title="Batalkan">✕</button>
                    </form>
                @endunless
            </div>
        @empty
            <p class="text-sm text-slate-400 px-1">Belum ada kiriman.</p>
        @endforelse
    </div>
</div>
@endsection

@push('scripts')
    <script src="{{ asset('js/foto-nota.js') }}?v={{ @filemtime(public_path('js/foto-nota.js')) }}"></script>
@endpush
