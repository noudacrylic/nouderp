<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip Gaji {{ $periode->label }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print { .no-print { display: none !important; } body { background: white; } }
        body { font-family: Arial, sans-serif; }
        .slip-page { page-break-after: always; }
        .slip-page:last-child { page-break-after: auto; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="no-print bg-white border-b px-6 py-3 flex justify-between items-center">
        <div class="text-sm text-gray-600">{{ $slips->count() }} slip — Periode {{ $periode->label }}</div>
        <div class="flex gap-2">
            <button onclick="window.print()" class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">🖨 Print Semua</button>
            <a href="{{ route('sdm.periode-gaji.show', $periode->id) }}" class="border px-3 py-1.5 rounded text-sm text-gray-700">Tutup</a>
        </div>
    </div>

    @foreach($slips as $slip)
        @php
            $attendances = \App\Modules\SDM\Models\Attendance::where('periode_id', $slip->periode_id)
                ->where('karyawan_id', $slip->karyawan_id)
                ->orderBy('tanggal')->get();
        @endphp
        <div class="slip-page max-w-4xl mx-auto bg-white p-8 mt-6 print:mt-0 print:p-4 shadow print:shadow-none">
            @include('erp.sdm.slip-gaji._body', ['slip' => $slip, 'attendances' => $attendances])
        </div>
    @endforeach

    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 500));
    </script>
</body>
</html>
