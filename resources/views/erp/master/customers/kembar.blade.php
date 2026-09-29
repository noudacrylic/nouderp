@extends('layouts.erp')

@section('content')
@php $adaOtomatis = $kelompok->contains(fn ($k) => $k['otomatis']->isNotEmpty()); @endphp

<div class="flex items-center justify-between mb-1">
    <h1 class="text-lg font-semibold">Pelanggan Kembar</h1>
    <div class="flex items-center gap-2">
        <a href="{{ route('customers.index') }}" class="px-3 py-2 rounded text-sm border border-gray-300 text-gray-600">← Customer</a>
        @if($adaOtomatis)
            <form method="POST" action="{{ route('customers.kembar.gabung') }}"
                  onsubmit="return confirm('Gabungkan semua pelanggan bernomor & bernama sama?')">
                @csrf
                <input type="hidden" name="semua_otomatis" value="1">
                <button class="bg-blue-600 text-white px-3 py-2 rounded text-sm">Gabung semua yang otomatis</button>
            </form>
        @endif
    </div>
</div>
<p class="text-xs text-gray-500 mb-4">
    Pelanggan dengan nomor HP yang sama. <b>Nomor + nama sama</b> digabung otomatis (tiap jam &amp; saat disimpan).
    <b>Nomor sama, nama beda</b> tidak digabung sendiri — bisa saja dua orang memakai satu nomor; putuskan di sini.
    Yang digabung diarsipkan, semua pesanan, faktur, pembayaran &amp; saldonya pindah ke pelanggan yang dipertahankan.
</p>

@forelse($kelompok as $i => $k)
    <form method="POST" action="{{ route('customers.kembar.gabung') }}"
          class="bg-white rounded shadow mb-3"
          onsubmit="return confirm('Gabungkan pelanggan yang dicentang ke pelanggan yang dipertahankan?')">
        @csrf
        <div class="px-3 py-2 border-b flex items-center gap-2 text-sm">
            <span class="font-semibold">📞 {{ $k['nomor'] }}</span>
            @if($k['cek'])
                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-100 text-amber-700">Nama beda — perlu dicek</span>
            @else
                <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-700">Nama sama — otomatis</span>
            @endif
            <button class="ml-auto px-3 py-1 rounded text-xs font-semibold border border-blue-400 text-blue-700 hover:bg-blue-50">Gabungkan</button>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-xs">
                <tr>
                    <th class="px-3 py-1.5 text-center w-24">Pertahankan</th>
                    <th class="px-3 py-1.5 text-center w-20">Gabung</th>
                    <th class="px-3 py-1.5 text-left">Kode</th>
                    <th class="px-3 py-1.5 text-left">Nama</th>
                    <th class="px-3 py-1.5 text-left">Telp</th>
                    <th class="px-3 py-1.5 text-right w-24">Pesanan</th>
                    <th class="px-3 py-1.5 text-center w-20">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($k['anggota'] as $j => $c)
                    <tr class="border-t">
                        <td class="px-3 py-1.5 text-center">
                            <input type="radio" name="ke" value="{{ $c->id }}" @checked($j === 0) required>
                        </td>
                        <td class="px-3 py-1.5 text-center">
                            {{-- Bawaan tercentang hanya bila namanya sama dengan yang tertua;
                                 nama beda dibiarkan kosong supaya tak tergabung karena klik asal. --}}
                            <input type="checkbox" name="dari[]" value="{{ $c->id }}"
                                   @checked($j > 0 && \App\Services\CustomerMergeService::kunciNama($c->name) === \App\Services\CustomerMergeService::kunciNama($k['anggota'][0]->name))>
                        </td>
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            <a href="{{ route('customers.edit', $c->id) }}" class="text-blue-700 hover:underline">{{ $c->code }}</a>
                        </td>
                        <td class="px-3 py-1.5 font-medium">{{ $c->name }}</td>
                        <td class="px-3 py-1.5">{{ $c->phone }}</td>
                        <td class="px-3 py-1.5 text-right">{{ $jumlahSo[$c->id] ?? 0 }}</td>
                        <td class="px-3 py-1.5 text-center">
                            <span class="px-2 py-0.5 rounded text-[11px] {{ $c->is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' }}">{{ $c->is_active ? 'Aktif' : 'Arsip' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </form>
@empty
    <div class="bg-white rounded shadow p-6 text-center text-sm text-gray-500">Tidak ada pelanggan kembar.</div>
@endforelse
@endsection
