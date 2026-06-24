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
    💡 Pengajuan dari aplikasi HP karyawan. <strong>Setujui</strong> → izin/override dibuat & slip (jika ada, belum final) di-regenerate.
    Toleransi langsung mengoreksi baris absensi (keterlambatan dinetralkan).
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
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($requests as $r)
                @php [$label, $cls] = $r->statusBadge(); @endphp
                <tr id="izin-{{ $r->id }}" class="scroll-mt-24 {{ request('highlight') == $r->id ? 'bg-amber-50 ring-2 ring-inset ring-amber-300' : '' }}">
                    <td class="px-3 py-2 font-medium text-gray-800">{{ $r->karyawan->name ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $r->typeLabel() }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ $r->tanggal->format('d/m/Y') }}
                        @if ($r->tanggal_akhir)<br><span class="text-xs text-gray-400">s/d {{ $r->tanggal_akhir->format('d/m/Y') }}</span>@endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 max-w-xs">{{ $r->alasan }}</td>
                    <td class="px-3 py-2 text-center">
                        @if ($r->lampiran_path)
                            <a href="{{ \Illuminate\Support\Facades\Storage::url($r->lampiran_path) }}" target="_blank" class="text-blue-600 underline text-xs">Foto</a>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center">
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full {{ $cls }}">{{ $label }}</span>
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        @if ($r->isPending())
                            <form method="POST" action="{{ route('sdm.pengajuan-izin.approve', $r->id) }}" class="inline"
                                  onsubmit="return confirm('Setujui pengajuan ini? Izin/override akan dibuat.');">
                                @csrf
                                <button class="bg-emerald-600 hover:bg-emerald-700 text-white px-2.5 py-1 rounded text-xs">Setujui</button>
                            </form>
                            <button type="button" onclick="document.getElementById('reject-{{ $r->id }}').classList.toggle('hidden')"
                                    class="bg-red-500 hover:bg-red-600 text-white px-2.5 py-1 rounded text-xs">Tolak</button>
                            <form method="POST" action="{{ route('sdm.pengajuan-izin.reject', $r->id) }}" id="reject-{{ $r->id }}" class="hidden mt-2 text-left">
                                @csrf
                                <input type="text" name="review_notes" placeholder="Alasan tolak (opsional)" class="border rounded px-2 py-1 text-xs w-48">
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Konfirmasi Tolak</button>
                            </form>
                        @elseif ($r->isApproved())
                            <span class="text-[11px] text-gray-400 mr-1">oleh {{ $r->reviewed_by }}</span>
                            <form method="POST" action="{{ route('sdm.pengajuan-izin.cancel', $r->id) }}" class="inline"
                                  onsubmit="return confirm('Batalkan approval & revert efeknya?');">
                                @csrf
                                <button class="bg-amber-500 hover:bg-amber-600 text-white px-2.5 py-1 rounded text-xs">Batalkan</button>
                            </form>
                        @else
                            <span class="text-[11px] text-gray-400">{{ $r->review_notes ?: 'ditolak' }}</span>
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
