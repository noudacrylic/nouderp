@extends('layouts.erp')

@section('content')
@include('erp.sdm.absensi._tabs')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pengajuan Izin Karyawan</h1>
    @if($pendingCount > 0)
        <span class="text-xs bg-amber-100 text-amber-800 font-semibold px-2.5 py-1 rounded-full">{{ $pendingCount }} menunggu</span>
    @endif
</div>

<div class="bg-blue-50 border border-blue-200 text-blue-700 px-3 py-2 rounded text-xs mb-3">
    💡 Pengajuan dari aplikasi HP karyawan. <strong>Klik barisnya</strong> untuk melihat kenyataan absensi hari itu —
    jadwal, scan mentah, dan catatan yang sudah ada — lalu putuskan di sana. Menyetujui akan membuat izin/override
    dan me-regenerate slip (jika ada dan belum final).
</div>

{{-- Filter status --}}
<div class="bg-white rounded shadow p-3 mb-3 flex gap-2 text-sm">
    @foreach (['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'all' => 'Semua'] as $key => $label)
        <a href="{{ route('sdm.pengajuan-izin.index', ['status' => $key]) }}"
           class="px-3 py-1.5 rounded {{ $status === $key ? 'bg-emerald-600 text-white font-semibold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-xs uppercase text-gray-500">
            <tr>
                <th class="px-3 py-2 text-left">Karyawan</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Alasan</th>
                <th class="px-3 py-2 text-center">Bukti</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right">Tinjau</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($requests as $r)
                @php [$label, $cls] = $r->statusBadge(); @endphp
                <tr id="izin-{{ $r->id }}"
                    onclick="window.location='{{ route('sdm.pengajuan-izin.show', $r->id) }}'"
                    class="cursor-pointer hover:bg-blue-50/60 scroll-mt-24 {{ request('highlight') == $r->id ? 'bg-amber-50 ring-2 ring-inset ring-amber-300' : '' }}">
                    <td class="px-3 py-2 font-medium text-gray-800">{{ $r->karyawan->name ?? '—' }}</td>
                    <td class="px-3 py-2">
                        {{ $r->typeLabel() }}
                        @if (!empty($adaScan[$r->id]))
                            <div class="text-[10px] font-bold text-red-700 bg-red-50 border border-red-200 rounded px-1.5 py-0.5 mt-1 inline-block"
                                 title="Ada scan fingerprint di tanggal yang diajukan — kemungkinan salah pilih tanggal">
                                ⚠ ada scan di tanggal itu
                            </div>
                        @endif
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ $r->tanggal->format('d/m/Y') }}
                        @if ($r->tanggal_akhir)<br><span class="text-xs text-gray-400">s/d {{ $r->tanggal_akhir->format('d/m/Y') }}</span>@endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 max-w-xs">{{ \Illuminate\Support\Str::limit($r->alasan, 80) }}</td>
                    <td class="px-3 py-2 text-center">
                        @if ($r->lampiran_path)
                            <span class="text-blue-600 text-xs">Ada</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full {{ $cls }}">{{ $label }}</span>
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <span class="text-blue-600 text-xs font-semibold">Tinjau →</span>
                        @if (!$r->isPending() && $r->reviewed_by)
                            <div class="text-[11px] text-gray-400">oleh {{ $r->reviewed_by }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Tidak ada pengajuan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $requests->links() }}</div>
@endsection
