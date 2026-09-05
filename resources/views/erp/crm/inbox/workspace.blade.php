@extends('layouts.erp')

@section('content')
{{-- Ruang kerja CRM: tiga segmen dengan batas tegas, tinggi tetap satu layar.

     Tinggi dipatok (bukan mengalir mengikuti isi) supaya kotak ketik SELALU
     menempel di bawah seperti aplikasi chat — kalau ikut mengalir, kotaknya
     melompat-lompat tergantung panjang thread dan admin harus menggulir untuk
     mengetik. Konsekuensinya tiap kolom wajib menggulir sendiri (min-h-0). --}}
<div class="flex flex-col h-[calc(100vh-7rem)] min-h-[32rem]">

    {{-- Tanpa judul & tombol modul: keduanya sudah ada di bilah menu tepat di
         atas layar ini. Mengulanginya hanya memakan tinggi, dan tinggi adalah
         hal paling berharga di layar chat. "Chat Baru" pindah ke kepala kolom
         kiri — tempatnya memang di sisi daftar percakapan. --}}
    @if($dryRun)
        <div class="shrink-0 mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            <b>Mode aman menyala.</b> Balasan tetap tercatat di thread, tapi tidak benar-benar dikirim ke pelanggan.
        </div>
    @endif

    <div class="flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-12 gap-3">

        <div class="lg:col-span-3 min-h-0">
            @include('erp.crm.inbox._daftar')
        </div>

        <div class="lg:col-span-6 min-h-0">
            @if($terpilih)
                @include('erp.crm.inbox._thread')
            @else
                <div class="h-full flex items-center justify-center bg-white border border-gray-200 rounded-lg">
                    <p class="text-sm text-gray-500 px-6 text-center">
                        Pilih percakapan di sebelah kiri.<br>
                        <span class="text-xs text-gray-400">Atau mulai yang baru lewat tombol <b>Chat Baru</b>.</span>
                    </p>
                </div>
            @endif
        </div>

        <div class="lg:col-span-3 min-h-0">
            @include('erp.crm.inbox._rail')
        </div>
    </div>
</div>
@endsection
