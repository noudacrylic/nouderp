{{-- Favicon SERAGAM = logo usaha (BusinessProfile). Dinamis ikut logo terbaru.
     Pakai path ROOT-RELATIVE (/storage/...), BUKAN URL absolut dari APP_URL,
     supaya konsisten di host mana pun (lokal 127.0.0.1 / ngrok / produksi).
     Fallback /favicon.png (salinan logo di public/) bila logo belum di-set. --}}
@php
    $bp = \App\Models\BusinessProfile::instance();
    $favicon = ($bp && $bp->logo_path) ? '/storage/' . ltrim($bp->logo_path, '/') : '/favicon.png';
@endphp
<link rel="icon" type="image/png" href="{{ $favicon }}">
<link rel="shortcut icon" href="{{ $favicon }}">
<link rel="apple-touch-icon" href="{{ $favicon }}">
