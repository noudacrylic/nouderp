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

    {{-- Pita MERAH, bukan kuning: layar yang webhook-nya patah terlihat persis
         seperti hari sepi — mengirim tetap berhasil, daftar tetap rapi, cuma
         tidak ada yang masuk. Tanpa peringatan sekeras ini, yang hilang bukan
         cuma centang melainkan balasan pelanggan, dan kejadian yang gagal
         diantar tidak pernah dikirim ulang vendor. --}}
    @if($webhookSepi ?? null)
        <div class="shrink-0 mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
            <b>Pesan masuk kemungkinan tidak sampai.</b>
            Balasan terakhir dikirim {{ $webhookSepi['kirim_terakhir']->diffForHumans() }}, tapi sejak itu
            @if($webhookSepi['sejak'])
                tidak ada kabar apa pun dari WhatsApp — yang terakhir masuk
                {{ $webhookSepi['sejak']->translatedFormat('d M Y H:i') }}.
            @else
                belum pernah ada kabar masuk dari WhatsApp sama sekali.
            @endif
            <div class="mt-1 text-xs">
                Penyebab tersering: endpoint webhook dimatikan vendor setelah gagal diantar berkali-kali
                (biasanya karena ERP sempat mati).
                <a href="{{ route('settings.crm.edit') }}" class="underline font-medium">Buka Pengaturan CRM</a>
                lalu tekan <b>Aktifkan Webhook</b>.
            </div>
        </div>
    @endif

    {{-- Sesi WhatsApp tak resmi putus. Gejalanya NOL dari layar mana pun:
         antrean menahan diri dengan rapi dan pelanggan tidak menerima apa-apa.
         Statusnya berasal dari penjaga terjadwal, bukan panggilan langsung —
         layar tidak boleh menggantung menunggu WAHA yang sedang mati. --}}
    @if(($wahaStatus ?? null) && ! $wahaStatus['siap'])
        <div class="shrink-0 mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
            <b>Notifikasi WhatsApp sedang berhenti.</b>
            Sesi berstatus <span class="font-mono">{{ $wahaStatus['status'] }}</span>
            @if($wahaStatus['diperiksa'])
                (diperiksa {{ $wahaStatus['diperiksa']->diffForHumans() }}).
            @else
                .
            @endif
            <div class="mt-1 text-xs">
                Notifikasi pesanan <b>ditahan di antrean</b>, tidak hilang, dan akan dialihkan ke template
                berbayar setelah {{ (int) config('crm.notifikasi.tahan_maks_jam', 3) }} jam.
                QR untuk menyambung ulang sudah dikirim ke Telegram — pindai dari HP pemegang nomornya.
            </div>
        </div>
    @endif

    {{-- Pendengar papan klip mode WhatsApp Web. Dipasang di LUAR grid
         supaya toast-nya tidak ikut terpotong kolom yang overflow-hidden. --}}
    @if($modeWa)
        @include('erp.crm.inbox._mode_wa')
    @endif

    {{-- Di mode WhatsApp Web sakelar menumpang di baris "Pilih kontak" supaya
         tidak memakan satu baris sendiri. --}}
    @unless($modeWa)
        <div class="shrink-0 mb-2 flex justify-end">@include('erp.crm.inbox._sakelar_mode_wa')</div>
    @endunless

    {{-- Di mode WhatsApp Web kolom thread DILIPAT, bukan dikosongkan: chat
         betulan dilayani di jendela web.whatsapp.com sebelah, dan gelembung
         yang tak akan pernah terisi cuma memakan lebar yang dibutuhkan panel.
         Kolom kiri ikut DISEMBUNYIKAN (permintaan 15 Sep 2026: pakai kanan
         saja), tapi tetap tergambar di balik tombol — dialah yang menentukan
         draft pesanan dan tombol Buat SO menempel ke kontak yang mana. Dihapus
         total berarti tab Pesanan tidak pernah bisa dipakai di mode ini. --}}
    @if($modeWa)
        <div x-data="{ daftar: false }" class="flex-1 min-h-0 flex flex-col gap-2">
            <div class="shrink-0 flex items-center gap-2 text-xs">
                <button type="button" @click="daftar = ! daftar"
                        class="px-2 py-1 rounded border border-gray-300 bg-white hover:bg-gray-50"
                        x-text="daftar ? 'Tutup daftar kontak' : 'Pilih kontak'"></button>
                <span class="flex-1 min-w-0 truncate text-gray-600">
                    @if($terpilih)
                        Kontak: <b>{{ $terpilih->namaTampil() }}</b>
                        <span class="text-gray-400">{{ $terpilih->contact_key }}</span>
                    @else
                        <span class="text-gray-400">Belum ada kontak dipilih — tab Pesanan butuh kontak.</span>
                    @endif
                </span>
                @include('erp.crm.inbox._sakelar_mode_wa')
            </div>

            <div x-show="daftar" x-cloak class="shrink-0 h-80 min-h-0">
                @include('erp.crm.inbox._daftar')
            </div>

            <div class="flex-1 min-h-0">
                @include('erp.crm.inbox._rail')
            </div>
        </div>
    @else
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
                        <span class="text-xs text-gray-400">Atau mulai yang baru lewat tombol <b>＋</b> di atas daftar.</span>
                    </p>
                </div>
            @endif
        </div>

        <div class="lg:col-span-3 min-h-0">
            @include('erp.crm.inbox._rail')
        </div>
    </div>
    @endif

    {{-- Satu popup untuk dua pintu masuk (tombol ＋ dan kotak jendela-tertutup).
         Dipasang di sini, di luar ketiga kolom, supaya tidak ikut terpotong
         oleh kolom yang overflow-hidden. --}}
    @include('erp.crm.inbox._mulai_chat')
</div>
@endsection
