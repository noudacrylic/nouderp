<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d3d2a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="NOUD Karyawan">
    <link rel="manifest" href="{{ asset('karyawan.webmanifest') }}">
    <title>@yield('title', 'NOUD Karyawan')</title>
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { --nav-h: 64px; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            -webkit-tap-highlight-color: transparent;
        }
        /* ruang untuk bottom-nav + safe area iOS */
        .app-main { padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px) + 12px); }
        .bottom-nav { padding-bottom: env(safe-area-inset-bottom, 0px); }
        /* sembunyikan scrollbar horizontal halus */
        ::-webkit-scrollbar { width: 0; height: 0; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="text-slate-800">

    {{-- Toast flash global (reuse partial ERP) --}}
    @include('layouts.partials._flash')

    <div class="max-w-md mx-auto min-h-screen bg-slate-50 shadow-sm relative">

        {{-- Header --}}
        <header class="bg-gradient-to-br from-[#15803d] via-[#10643a] to-[#0d3d2a] text-white px-5 pt-6 pb-5 rounded-b-3xl shadow-lg">
            @yield('header')
        </header>

        {{-- Konten --}}
        <main class="app-main px-4 -mt-4">
            @yield('content')
        </main>

        {{-- Bottom navigation --}}
        @include('me.partials._bottom_nav')
    </div>

    @stack('scripts')

    {{-- Alpine (dipakai oleh _flash & komponen) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    {{-- Service worker (Add to Home Screen / installable) --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ url('/me/sw.js') }}', { scope: '/me/' }).catch(function () {});
            });
        }
    </script>
</body>
</html>
