<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terima Kasih — Noud Acrylic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Outfit', sans-serif; }</style>
    @unless($trx?->isPaid())
        <meta http-equiv="refresh" content="8">
    @endunless
</head>
<body class="bg-gradient-to-br from-emerald-50 via-white to-emerald-50 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl text-center p-8">
        @php $isSo = $is_so ?? false; @endphp
        @if ($trx?->isPaid())
            <div class="mx-auto w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-9 h-9 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            @if ($isSo)
                <h1 class="text-2xl font-bold text-gray-800 mb-2">Uang Muka Diterima</h1>
                <p class="text-sm text-gray-600">
                    Terima kasih, uang muka (DP) untuk pesanan
                    <span class="font-semibold">{{ $so?->order_number ?? '-' }}</span> sudah kami terima.
                </p>
                <div class="mt-4 text-2xl font-bold text-emerald-700">
                    Rp {{ number_format((float) $trx->gross_amount, 0, ',', '.') }}
                </div>
                @if ($so)
                    <div class="mt-3 text-sm text-gray-600">
                        Sisa tagihan: <span class="font-semibold">Rp {{ number_format(max(0, $so->grand_total - $so->paid_amount), 0, ',', '.') }}</span>
                    </div>
                @endif
            @else
                <h1 class="text-2xl font-bold text-gray-800 mb-2">Pembayaran Berhasil</h1>
                <p class="text-sm text-gray-600">
                    Terima kasih atas pembayaran untuk invoice
                    <span class="font-semibold">{{ $invoice?->invoice_number ?? '-' }}</span>.
                </p>
                <div class="mt-4 text-2xl font-bold text-emerald-700">
                    Rp {{ number_format((float) $trx->gross_amount, 0, ',', '.') }}
                </div>
            @endif
            <p class="text-xs text-gray-500 mt-6">Status pembayaran sudah tercatat di sistem kami.</p>
        @else
            <div class="mx-auto w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mb-4">
                <svg class="w-9 h-9 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Menunggu Konfirmasi</h1>
            <p class="text-sm text-gray-600">
                Pembayaran sedang diproses. Halaman ini akan refresh otomatis.
            </p>
            <p class="text-xs text-gray-500 mt-6">Jika sudah membayar, mohon tunggu beberapa saat.</p>
        @endif
    </div>
</body>
</html>
