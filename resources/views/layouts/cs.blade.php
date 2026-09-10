<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f766e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="NOUD Chat">
    <link rel="manifest" href="{{ asset('cs.webmanifest') }}">
    <title>@yield('title', 'NOUD Chat')</title>
    @include('layouts.partials._favicon')
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/icon-180.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { --nav-h: 60px; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f1f5f9;
            -webkit-tap-highlight-color: transparent;
            overscroll-behavior-y: none;
        }
        /* Tinggi dipatok ke layar, BUKAN mengalir mengikuti isi — sama alasannya
           dengan ruang kerja CRM desktop: kotak ketik harus menempel di bawah,
           dan di HP keyboard yang muncul tidak boleh mendorongnya keluar layar.
           100dvh (bukan 100vh) supaya bilah URL Safari yang menyusut ikut dihitung. */
        .app-shell { height: 100dvh; display: flex; flex-direction: column; }
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
