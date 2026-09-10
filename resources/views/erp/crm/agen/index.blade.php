@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Agen AI</h1>
    <a href="{{ route('crm.inbox.index') }}" class="text-sm text-emerald-700 hover:underline">← Kembali ke Inbox</a>
</div>

{{-- Keadaan saklar induk dinyatakan di depan, bukan disembunyikan di pengaturan.
     Selama ia mati, seluruh kolom "Hidup" di bawah tidak berarti apa-apa — dan
     tidak ada yang lebih membingungkan daripada agen bertanda hidup yang tak
     pernah membalas. --}}
@if($induk)
    <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
        <b>Saklar induk menyala.</b> Agen yang ditandai hidup di bawah boleh membalas pelanggan sungguhan.
    </div>
@else
    <div class="mb-4 rounded border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
        <b>Saklar induk mati</b> (<span class="font-mono">CRM_AGEN_AKTIF</span>).
        Belum ada satu pun agen yang bisa membalas pelanggan — apa pun tanda di bawah.
        Tombol <b>Uji</b> tetap jalan: ia memang tidak pernah menyentuh pelanggan.
    </div>
@endif

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="text-left px-3 py-2">Agen</th>
                <th class="text-left px-3 py-2 w-40">Model</th>
                <th class="text-left px-3 py-2 w-32">Pengetahuan</th>
                <th class="text-right px-3 py-2 w-28">Dijalankan</th>
                <th class="text-right px-3 py-2 w-32">Biaya bulan ini</th>
                <th class="text-left px-3 py-2 w-24">Hidup</th>
                <th class="px-3 py-2 w-24"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach($agen as $a)
                @php
                    $aktif = $a->pengetahuanAktif();
                    $ringkas = $bulanIni[$a->id] ?? null;
                @endphp
                <tr>
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $a->nama }}</div>
                        <div class="text-xs text-gray-500">{{ $a->deskripsi }}</div>
                    </td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $a->modelDipakai() }}</td>
                    <td class="px-3 py-2">
                        @if($aktif)
                            <span class="text-emerald-700">versi {{ $aktif->versi }}</span>
                        @else
                            <span class="text-gray-400">belum ada</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ (int) ($ringkas->jumlah ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">Rp{{ number_format((float) ($ringkas->biaya ?? 0), 0, ',', '.') }}</td>
                    <td class="px-3 py-2">
                        @if($a->is_active)
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-emerald-100 text-emerald-700">hidup</span>
                        @else
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-gray-100 text-gray-600">mati</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('crm.agen.edit', $a) }}"
                           class="text-xs px-2 py-1 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50">Buka</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<p class="mt-3 text-xs text-gray-500">
    Biaya dihitung dari pemakaian token tiap kali agen dijalankan, memakai tarif model yang benar-benar dipakai —
    termasuk yang dijalankan lewat tombol Uji. Setelah beberapa hari mencoba, angka di kolom itu sudah terukur,
    bukan ditebak dari daftar harga.
</p>
@endsection
