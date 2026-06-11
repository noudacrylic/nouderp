@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Accounting Period</h1>
    <form method="POST" action="{{ route('accounting.period.ensure-current') }}">@csrf
        <button class="bg-blue-600 text-white px-3 py-1.5 rounded text-sm">Buat Periode Bulan Ini</button>
    </form>
</div>


<div class="bg-blue-50 border border-blue-200 text-blue-800 px-3 py-2 rounded mb-3 text-sm">
    Saat menutup periode, sistem otomatis menjalankan penyusutan aset tetap untuk periode tsb dan mengunci jurnal di periode itu (tidak bisa post baru / void existing).
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Periode</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-left">Closed At</th>
                <th class="px-3 py-2 text-right w-72">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($periods as $p)
                <tr class="border-b">
                    <td class="px-3 py-2 font-medium">{{ $p->year }}-{{ str_pad($p->month, 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="px-3 py-2 text-xs">{{ $p->start_date }} → {{ $p->end_date }}</td>
                    <td class="px-3 py-2 text-center">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $p->status === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600' }}">{{ $p->status }}</span>
                    </td>
                    <td class="px-3 py-2 text-xs">{{ $p->closed_at }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($p->status === 'open')
                                <a href="{{ route('accounting.period.preview', [$p->year, $p->month]) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Preview &amp; Tutup</a>
                            @else
                                <form method="POST" action="{{ route('accounting.period.reopen', [$p->year, $p->month]) }}" onsubmit="return confirm('Buka kembali periode ini? Hanya lanjutkan jika memang perlu koreksi historis.')">@csrf
                                    <button class="bg-yellow-600 text-white px-2 py-1 rounded text-xs">Reopen</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Belum ada periode.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $periods->links() }}</div>
@endsection
