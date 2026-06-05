<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link Tidak Valid — Noud Acrylic</title>
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Outfit', sans-serif; }</style>
</head>
<body class="bg-gradient-to-br from-rose-50 via-white to-rose-50 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl text-center p-8">
        <div class="mx-auto w-16 h-16 bg-rose-100 rounded-full flex items-center justify-center mb-4">
            <svg class="w-9 h-9 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Tautan Tidak Berlaku</h1>
        <p class="text-sm text-gray-600">{{ $reason ?? 'Link pembayaran tidak ditemukan.' }}</p>
        <p class="text-xs text-gray-500 mt-6">Mohon hubungi Noud Acrylic untuk mendapatkan link pembayaran baru.</p>
    </div>
</body>
</html>
