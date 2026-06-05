<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip {{ $slip->code }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print { .no-print { display: none !important; } body { background: white; } }
        body { font-family: Arial, sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="no-print bg-white border-b px-6 py-3 flex justify-between items-center">
        <div class="text-sm text-gray-600">Pratinjau slip — pakai tombol Cetak untuk mencetak.</div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">🖨 Cetak</button>
            <a href="{{ route('sdm.slip-gaji.show', $slip->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700">Tutup</a>
        </div>
    </div>

    <div class="max-w-4xl mx-auto bg-white p-8 mt-6 print:mt-0 print:p-4 shadow print:shadow-none">
        @include('erp.sdm.slip-gaji._body')
    </div>

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
