<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $is_so ? 'Pembayaran Uang Muka (DP)' : 'Pembayaran Invoice' }} — Noud Acrylic</title>
    @include('layouts.partials._favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Outfit', sans-serif; }</style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-white to-emerald-50 min-h-screen flex items-center justify-center px-4 py-8">

    @if($is_so)
        @include('pay._so')
    @else
        @include('pay._invoice')
    @endif

</body>
</html>
