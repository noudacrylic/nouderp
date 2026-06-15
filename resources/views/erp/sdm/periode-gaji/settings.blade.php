@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="bg-white rounded shadow p-4 mb-3">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h2 class="font-semibold text-gray-800">Pengaturan Periode</h2>
            <p class="text-xs text-gray-500 mt-1 max-w-2xl leading-relaxed">
                Periode absensi/penggajian dibuat <b>otomatis tiap tanggal 1 pukul 00:01</b> (selama cron server aktif),
                dan juga otomatis terbentuk saat scan fingerprint pertama bulan berjalan masuk. Satu periode per bulan
                untuk seluruh perusahaan. Tombol di samping untuk membuatnya manual kapan saja.
            </p>
        </div>
        <form method="POST" action="{{ route('sdm.periode-gaji.ensure-current') }}" class="shrink-0">
            @csrf
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm font-semibold whitespace-nowrap">
                + Buat Periode Bulan Ini ({{ $currentLabel }})
            </button>
        </form>
    </div>
    @if($currentExists)
        <p class="text-[11px] text-emerald-600 mt-2">✓ Periode {{ $currentLabel }} sudah ada.</p>
    @else
        <p class="text-[11px] text-amber-600 mt-2">⚠ Periode {{ $currentLabel }} belum ada — klik tombol untuk membuatnya.</p>
    @endif
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Kode</th>
                <th class="px-3 py-2 text-left">Periode</th>
                <th class="px-3 py-2 text-left">Rentang</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-center w-24">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($periodes as $p)
                <tr class="border-b hover:bg-blue-50">
                    <td class="px-3 py-2 font-mono whitespace-nowrap">{{ $p->code }}</td>
                    <td class="px-3 py-2">{{ $p->label }}</td>
                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($p->start_date)->format('d/m/y') }}
                        – {{ \Illuminate\Support\Carbon::parse($p->end_date)->format('d/m/y') }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        @php
                            $badge = match($p->status) {
                                'open'      => 'bg-blue-100 text-blue-700',
                                'finalized' => 'bg-green-100 text-green-700',
                                'void'      => 'bg-red-100 text-red-600',
                                default     => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $badge }}">{{ $p->status_label }}</span>
                    </td>
                    <td class="px-3 py-2 text-center">
                        <a href="{{ route('sdm.periode-gaji.show', $p->id) }}" class="text-blue-600 hover:underline text-xs">Detail</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada periode.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
