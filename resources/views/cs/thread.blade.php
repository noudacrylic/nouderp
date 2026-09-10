@extends('layouts.cs', ['tanpaNav' => true, 'mainKelas' => 'overflow-hidden'])

@section('title', ($terpilih->customer->name ?? $terpilih->display_name ?? $terpilih->contact_key) . ' — NOUD Chat')

{{-- Menu bawah SENGAJA hilang di layar ini. Di dalam satu percakapan, ruang
     paling bawah milik kotak ketik; nav yang bertahan di situ cuma jadi sasaran
     salah tekan saat mengetik. Jalan keluarnya tombol ← di kepala. --}}

@section('content')
<div class="h-full" x-data="{ alat: false }" @buka-tab.window="alat = true">

    @include('erp.crm.inbox._thread', ['modePwa' => true])

    {{-- Lembar geser: pengganti kolom kanan desktop.

         Dirender SELALU (cuma digeser keluar layar), bukan lewat x-if. Sama
         alasannya dengan panel ongkir di rail: pelengkap-otomatis wilayah
         mengikat elemennya lewat id saat halaman dimuat, jadi panel yang baru
         lahir saat tombol ditekan tidak akan pernah terpasang. --}}
    <div class="fixed inset-0 z-40" x-show="alat" x-cloak>
        <div class="absolute inset-0 bg-black/40" @click="alat = false"></div>

        <div class="absolute inset-x-0 bottom-0 max-w-md mx-auto h-[85dvh] bg-white rounded-t-2xl
                    shadow-2xl flex flex-col overflow-hidden"
             @keydown.escape.window="alat = false">

            {{-- Gagang geser + tombol tutup. Tanpa tombol yang jelas, satu-satunya
                 jalan keluar adalah menekan latar gelap yang sempit di atasnya. --}}
            <div class="shrink-0 flex items-center justify-between px-3 pt-2 pb-1 border-b border-gray-200">
                <span class="w-9"></span>
                <span class="w-10 h-1 rounded-full bg-gray-300"></span>
                <button type="button" @click="alat = false"
                        class="w-9 h-8 text-gray-400 active:text-gray-700 text-lg leading-none">✕</button>
            </div>

            <div class="flex-1 min-h-0">
                @include('erp.crm.inbox._rail', ['gayaWadah' => 'bg-white'])
            </div>
        </div>
    </div>
</div>
@endsection
