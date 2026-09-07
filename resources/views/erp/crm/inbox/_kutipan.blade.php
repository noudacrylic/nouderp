{{-- Kotak kutipan di dalam gelembung — bentuk yang sama dipakai WhatsApp:
     pita warna di kiri, nama pengirim di atas, satu baris isi di bawah.

     Dipakai DUA tempat: gelembung yang sudah tersimpan (di sini) dan strip
     kutipan di atas kotak ketik (dirakit skrip). Bentuknya sengaja dijaga
     sama supaya kutipan yang dilihat admin sebelum mengirim persis dengan
     yang muncul setelah terkirim.

     $dikutip boleh null: pesan yang dikutip bisa lebih tua dari ERP ini
     (percakapan lama yang ditarik dari HP), atau percakapannya sudah dihapus.
     Kutipan yang menunjuk ke ruang kosong tetap harus terbaca sebagai sesuatu,
     bukan jadi kotak melompong yang terlihat seperti tampilan rusak. --}}
@php $milikKita = $dikutip && ! $dikutip->isInbound(); @endphp

<div @if($dikutip) data-lompat="{{ $dikutip->id }}" role="button" tabindex="0"
         title="Lihat pesan aslinya" @endif
     class="mb-1.5 flex gap-2 rounded overflow-hidden bg-black/5 {{ $dikutip ? 'cursor-pointer hover:bg-black/10' : '' }}">
    <div class="w-1 shrink-0 {{ $milikKita ? 'bg-emerald-500' : 'bg-sky-500' }}"></div>
    <div class="min-w-0 py-1 pr-2">
        @if($dikutip)
            <div class="text-[11px] font-semibold {{ $milikKita ? 'text-emerald-700' : 'text-sky-700' }}">
                {{ $dikutip->labelPengirim() }}
            </div>
            <div class="text-[12px] text-gray-600 truncate">{{ $dikutip->ringkas() }}</div>
        @else
            <div class="text-[12px] text-gray-500 italic py-0.5">Pesan yang dikutip tidak ada di riwayat ERP.</div>
        @endif
    </div>
</div>
