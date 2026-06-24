<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title>Offline — NOUD Karyawan</title>
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-6 text-center">
    <div class="max-w-sm">
        <p class="text-5xl mb-3">📶</p>
        <h1 class="text-lg font-bold text-slate-800 mb-1">Tidak ada koneksi</h1>
        <p class="text-sm text-slate-500 mb-5">Periksa jaringan internet Anda lalu coba lagi.</p>
        <a href="{{ url('/me') }}" class="inline-block bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-lg">Coba lagi</a>
    </div>
</body>
</html>
