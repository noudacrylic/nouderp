@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Notifikasi Pesanan</h1>
    <div class="flex gap-2">
        <a href="{{ route('crm.inbox.index') }}" class="border border-gray-300 hover:bg-gray-50 px-3 py-2 rounded text-sm">← Inbox</a>
        <form method="POST" action="{{ route('crm.notifikasi.kirim') }}">
            @csrf
            <button class="border border-blue-600 text-blue-600 hover:bg-blue-50 px-3 py-2 rounded text-sm">Kirim yang Jatuh Tempo</button>
        </form>
    </div>
</div>

@if($dryRun)
    <div class="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        <b>Mode aman menyala.</b> Notifikasi ditandai terkirim untuk menguji alurnya, tapi tidak ada yang sampai ke pelanggan.
    </div>
@endif

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['menunggu' => 'Menunggu', 'terkirim' => 'Terkirim', 'gagal' => 'Gagal', 'dilewati' => 'Dilewati'] as $val => $label)
                <option value="{{ $val }}" @selected(request('status')===$val)>{{ $label }} ({{ $jumlah[$val] ?? 0 }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Jenis</label>
        <select name="event" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="pembayaran_diterima" @selected(request('event')==='pembayaran_diterima')>Pembayaran Diterima</option>
            <option value="siap_diambil" @selected(request('event')==='siap_diambil')>Siap Diambil</option>
            <option value="dikirim" @selected(request('event')==='dikirim')>Dikirim</option>
        </select>
    </div>
    @include('erp._partials.per-page-select')
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Pesanan</th>
                <th class="px-3 py-2 text-left">Jenis</th>
                <th class="px-3 py-2 text-left">Tujuan</th>
                <th class="px-3 py-2 text-left">Jadwal / Terkirim</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-right w-28">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($daftar as $n)
                @php
                    $stCls = match($n->status) {
                        'terkirim' => 'bg-green-100 text-green-700',
                        'gagal'    => 'bg-red-100 text-red-700',
                        'dilewati' => 'bg-gray-100 text-gray-600',
                        default    => 'bg-yellow-100 text-yellow-700',
                    };
                @endphp
                <tr class="border-b">
                    <td class="px-3 py-2 whitespace-nowrap">{{ $n->salesOrder->order_number ?? '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $n->event }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $n->recipient ?: '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                        @if($n->sent_at)
                            {{ $n->sent_at->translatedFormat('d M Y H:i') }}
                        @elseif($n->scheduled_at)
                            dijadwalkan {{ $n->scheduled_at->translatedFormat('d M Y H:i') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-xs {{ $stCls }}">{{ $n->status }}</span>
                        {{-- Alasan adalah inti layar ini: menjawab "kenapa pelanggan
                             ini tidak dapat kabar?" tanpa membuka basis data. --}}
                        @if($n->reason)
                            <div class="text-xs text-gray-500 mt-0.5">{{ $n->reason }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($n->status === 'gagal')
                            <form method="POST" action="{{ route('crm.notifikasi.ulangi', $n->id) }}">
                                @csrf
                                <button class="border border-blue-600 text-blue-600 hover:bg-blue-50 px-2 py-1 rounded text-xs">Coba Lagi</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Belum ada notifikasi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $daftar->links() }}</div>
@endsection
