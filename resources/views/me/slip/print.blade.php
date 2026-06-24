<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip {{ $slip->code }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print { .no-print { display: none !important; } body { background: white; } }
        body { font-family: Arial, sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="no-print bg-white border-b px-4 py-3 flex justify-between items-center sticky top-0">
        <a href="{{ route('me.slip') }}" class="text-sm text-gray-600">← Kembali</a>
        <button onclick="window.print()" class="bg-emerald-600 text-white px-3 py-1.5 rounded text-sm font-semibold">🖨 Cetak / Simpan PDF</button>
    </div>

    <div class="max-w-4xl mx-auto bg-white p-4 sm:p-8 mt-4 print:mt-0 print:p-4 shadow print:shadow-none overflow-x-auto">
        @include('erp.sdm.slip-gaji._body')
    </div>
</body>
</html>
