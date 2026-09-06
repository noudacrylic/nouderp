{{-- Penanda kirim ala WhatsApp untuk pesan KELUAR.

     Satu-satunya sumber markup centang: dipakai render Blade (gelembung yang
     sudah tersimpan) dan DIKLON oleh skrip untuk gelembung sementara. Kalau
     tiap jalur menggambar centangnya sendiri, yang satu cepat berbeda dari
     yang lain — persis alasan _bubbles dipisah.

     Semua keadaan digambar sekaligus, yang tampil dipilih CSS lewat
     data-status. Dengan begitu memperbarui status = mengganti satu atribut,
     tanpa membangun ulang HTML dari skrip. --}}
@php
    $st = $status ?? 'menunggu';

    $judul = match ($st) {
        'menunggu' => 'Belum terkirim — masih dalam perjalanan',
        'terkirim' => 'Terkirim ke WhatsApp',
        'sampai'   => 'Sampai di HP pelanggan',
        'dibaca'   => 'Dibaca pelanggan',
        default    => '',
    };
@endphp
<span class="crm-centang" data-status="{{ $st }}" @if($judul) title="{{ $judul }}" @endif>
    {{-- menunggu --}}
    <svg class="ct-jam" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="9"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l3 2"/>
    </svg>
    {{-- terkirim --}}
    <svg class="ct-satu" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5 9 17.5 20 6.5"/>
    </svg>
    {{-- sampai & dibaca (bedanya cuma warna) --}}
    <svg class="ct-dua" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M1 12.5 6 17.5 17 6.5"/>
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 12.5 12 17.5 23 6.5"/>
    </svg>
</span>
