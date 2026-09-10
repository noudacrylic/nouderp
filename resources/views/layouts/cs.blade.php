<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    {{-- interactive-widget=resizes-content WAJIB. Bawaan Chrome Android adalah
         resizes-visual: keyboard yang muncul TIDAK mengecilkan layout viewport,
         halamannya cuma digeser ke atas — kepala chat, strip Produk/Ongkir/Pesanan,
         dan percakapannya terdorong keluar layar sementara 100dvh tetap merasa
         setinggi layar penuh. Dengan resizes-content, tinggi layar benar-benar
         menyusut dan susunan flex (kepala tetap · pesan menggulir · kotak ketik
         menempel) menyesuaikan diri sendiri. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f766e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="NOUD Chat">
    <link rel="manifest" href="{{ asset('cs.webmanifest') }}">
    <title>@yield('title', 'NOUD Chat')</title>
    @include('layouts.partials._favicon')
    {{-- Ikon SENDIRI, bukan centang hijau milik PWA Karyawan. Keduanya duduk
         berdampingan di layar HP yang sama: dua ikon mirip berarti CS membuka
         aplikasi perizinan setiap kali buru-buru membalas pelanggan. --}}
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/cs-icon-180.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { --nav-h: 60px; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f1f5f9;
            -webkit-tap-highlight-color: transparent;
            overscroll-behavior-y: none;
        }
        /* Halaman TIDAK boleh menggulir sama sekali — yang menggulir cuma daftar
           pesan di dalamnya. Tanpa ini, keyboard iOS menggeser seluruh dokumen
           ke atas dan kepala chat hilang di balik tepi layar. */
        html, body { height: 100%; overflow: hidden; }
        /* Tinggi dipatok ke layar, BUKAN mengalir mengikuti isi — kotak ketik harus
           menempel di bawah, dan keyboard yang muncul tidak boleh mendorongnya
           keluar layar. 100dvh (bukan 100vh) supaya bilah URL Safari yang menyusut
           ikut dihitung, dan --tinggi-app diisi skrip dari visualViewport untuk peramban yang belum
           mengenal interactive-widget (Safari iOS). 100dvh tetap jadi nilai
           mundur, jadi layarnya benar bahkan sebelum skrip sempat jalan. */
        .app-shell { height: 100dvh; height: var(--tinggi-app, 100dvh); display: flex; flex-direction: column; }
        /* Lembar geser ikut tinggi yang sama: 'fixed inset-0' mengacu ke layout
           viewport, yang saat keyboard terbuka jauh lebih tinggi dari yang
           benar-benar terlihat — panelnya jadi menggantung di bawah keyboard. */
        .lapis-layar { height: 100dvh; height: var(--tinggi-app, 100dvh); }
        .bottom-nav { padding-bottom: env(safe-area-inset-bottom, 0px); }
        ::-webkit-scrollbar { width: 0; height: 0; }
        [x-cloak] { display: none !important; }
    </style>
    @stack('head')
</head>
<body class="text-slate-800">

    {{-- Toast flash global (reuse partial ERP) --}}
    @include('layouts.partials._flash')

    <div class="app-shell max-w-md mx-auto bg-slate-50 shadow-sm">

        {{-- Kepala layar. Tiap halaman menentukan isinya sendiri: daftar chat
             butuh pencarian, dialog chat butuh nama pelanggan + tombol kembali. --}}
        @hasSection('topbar')
            <header class="shrink-0 bg-[#0f766e] text-white shadow">
                @yield('topbar')
            </header>
        @endif

        {{-- min-h-0 WAJIB: tanpa itu flex child menolak menyusut dan yang
             menggulir jadi seluruh halaman, bukan isinya — bottom nav ikut hanyut. --}}
        {{-- Layar chat mengurus gulirnya SENDIRI (kepala tetap di atas, kotak
             ketik tetap di bawah), jadi ia menimpa ini dengan overflow-hidden.
             Kalau main ikut menggulir, kotak ketik ikut hanyut ke luar layar. --}}
        <main class="flex-1 min-h-0 {{ $mainKelas ?? 'overflow-y-auto' }}">
            @yield('content')
        </main>

        @unless($tanpaNav ?? false)
            @include('cs.partials._bottom_nav')
        @endunless
    </div>

    @stack('scripts')

    {{-- Keyboard di layar: Safari iOS belum mengenal interactive-widget, jadi
         tingginya diukur sendiri lewat visualViewport. Tanpa ini gejalanya
         persis seperti tanpa perbaikan apa pun di Android — semuanya tergeser
         ke atas dan percakapannya tidak kelihatan. --}}
    <script>
        (function () {
            var vv = window.visualViewport;

            if (! vv) return;

            var akar = document.documentElement;
            var sebelumnya = vv.height;

            function ukur() {
                akar.style.setProperty('--tinggi-app', vv.height + 'px');

                /*
                 * iOS menggeser DOKUMEN saat keyboard naik, bukan mengecilkan
                 * layout viewport. Menariknya balik ke 0 adalah satu-satunya
                 * cara membuat kepala chat tetap di tempatnya; tanpa ini tinggi
                 * yang sudah benar pun tetap tampak terpotong di atas.
                 */
                window.scrollTo(0, 0);

                /*
                 * Layar yang MENGECIL berarti keyboard baru saja naik. Yang
                 * paling menjengkelkan bukan tingginya, melainkan pesan terakhir
                 * yang tertutup begitu kotak ketik naik — jadi daftar pesannya
                 * dikabari supaya menempel lagi ke bawah.
                 */
                window.dispatchEvent(new CustomEvent('layar-berubah', {
                    detail: { tinggi: vv.height, mengecil: vv.height < sebelumnya - 40 },
                }));

                sebelumnya = vv.height;
            }

            vv.addEventListener('resize', ukur);
            vv.addEventListener('scroll', function () { window.scrollTo(0, 0); });
            ukur();
        })();
    </script>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    {{-- Service worker (Add to Home Screen / installable). Scope /cs/ terpisah
         dari PWA Karyawan di /me/ — dua aplikasi, dua service worker, tidak
         saling menimpa cache maupun langganan push. --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ url('/cs/sw.js') }}', { scope: '/cs/' }).catch(function () {});
            });
        }
    </script>
</body>
</html>
