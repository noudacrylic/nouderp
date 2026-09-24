@extends('layouts.cs', ['mainKelas' => 'overflow-hidden'])

@section('title', 'Chat — NOUD Chat')

{{-- Tanpa bilah judul sendiri: kepala daftar chat (siapa · cari · label) sudah
     menempati tiga baris teratas, dan menambahkan bilah "Chat" di atasnya cuma
     memakan tinggi yang seharusnya jadi percakapan yang terlihat. --}}

@section('content')
    {{-- Daftar yang sama persis dengan kolom kiri ERP — hanya bingkainya dilepas
         (layar penuh, bukan kartu) dan tiap baris menuju layar chat PWA. --}}
    {{-- Pembungkus ber-id: penyegar menukar ISI elemen ini, jadi ia harus ada
         dan harus membungkus daftarnya saja. --}}
    <div id="cs-daftar" class="h-full min-h-0">
        @include('erp.crm.inbox._daftar', [
            'rutaChat'       => 'cs.thread',
            'gayaWadah'      => 'bg-white',
            'tumbuhOtomatis' => true,
        ])
    </div>

    {{-- Tanpa ini daftar chat di HP tidak pernah bergerak sendiri: pesan baru
         hanya muncul setelah CS membuka chat lain lalu kembali. --}}
    @include('erp.crm.inbox._daftar_segar', ['wadahId' => 'cs-daftar', 'aplikasi' => 'cs'])

    {{-- Popup "Mulai Chat" dipanggil tombol ＋ di kepala daftar. Tanpa ini
         tombolnya ada tapi diam saja saat ditekan. --}}
    @include('erp.crm.inbox._mulai_chat')
@endsection
