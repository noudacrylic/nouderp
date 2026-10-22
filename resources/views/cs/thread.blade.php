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
    {{-- lapis-layar menimpa tinggi 'inset-0': saat keyboard terbuka, layout
         viewport masih setinggi layar penuh sedangkan yang terlihat cuma
         separuhnya — lembar tanpa tinggi yang benar berakhir menggantung di
         balik keyboard. Kelasnya cuma hidup di layout /cs; di ERP desktop
         elemen yang sama tetap memakai inset-0 apa adanya. --}}
    <div class="fixed inset-0 z-40 lapis-layar" x-show="alat" x-cloak>
        <div class="absolute inset-0 bg-black/40" @click="alat = false"></div>

        <div class="absolute inset-x-0 bottom-0 max-w-md mx-auto bg-white rounded-t-2xl
                    shadow-2xl flex flex-col overflow-hidden"
             style="height: calc(var(--tinggi-app, 100dvh) * 0.85)"
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

@push('scripts')
    <script>
        (function () {
            var DAFTAR = @json(route('cs.chat'));
            var adaDaftar = false;
            try { adaDaftar = sessionStorage.getItem('cs-ada-daftar') === '1'; } catch (e) {}

            /*
             * Chat yang dibuka langsung dari notifikasi tidak punya inbox di
             * belakangnya — tombol back HP akan menutup aplikasi. Sisipkan satu
             * entri "inbox" di bawah chat ini; saat entri itu dicapai, inbox
             * dimuat sungguhan.
             */
            if (! adaDaftar && ! (history.state && history.state.csChat)) {
                var sini = location.href;
                history.replaceState({ csDaftarSemu: true }, '', DAFTAR);
                history.pushState({ csChat: true }, '', sini);
                try { sessionStorage.setItem('cs-ada-daftar', '1'); } catch (e) {}
            }

            window.addEventListener('popstate', function (e) {
                if (e.state && e.state.csDaftarSemu) location.replace(DAFTAR);
            });

            /* Tombol ← = tombol back HP. Dulu ← membuka inbox BARU dan
               menumpuknya di riwayat, sehingga back HP sesudahnya berjalan ke
               layar lama yang beku. Cadangan: kalau mundur tidak berpindah
               halaman (riwayat kosong), buka inbox biasa. */
            document.addEventListener('click', function (e) {
                var a = e.target.closest && e.target.closest('a[data-kembali-daftar]');
                if (! a) return;
                e.preventDefault();
                history.back();
                var cadangan = setTimeout(function () { location.href = DAFTAR; }, 600);
                // Halaman ini masuk bfcache saat mundur: timernya jangan sampai
                // menyala belakangan kalau halaman ini dihidupkan lagi.
                window.addEventListener('pagehide', function () { clearTimeout(cadangan); }, { once: true });
            });
        })();
    </script>
@endpush
