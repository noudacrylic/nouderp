@extends('layouts.erp')

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('karyawan-virtual.show', $kode) }}" class="inline-block text-xs text-blue-600 hover:underline mb-3">← {{ $karyawan['ikon'] }} {{ $karyawan['nama'] }}</a>

    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-1">Produk Butuh Desain</h2>
        <p class="text-sm text-gray-500 mb-4">
            Pesanan yang berisi produk di daftar ini dibuatkan task desain oleh skill <b>Desain pesanan</b> (satu task per pesanan).
            Tulis <b>SKU persis</b> untuk satu produk (mis. <span class="font-mono">TBKD-13</span>), atau <b>awalan + *</b> untuk satu keluarga produk
            (mis. <span class="font-mono">KS-*</span> = semua Kotak Saran, termasuk varian yang nanti ditambahkan).
        </p>

        <form method="POST" action="{{ route('karyawan-virtual.admin.produk-desain.tambah', $kode) }}" class="flex flex-wrap gap-2 mb-5">
            @csrf
            <input name="pola" value="{{ old('pola') }}" required maxlength="60" placeholder="SKU atau awalan*, mis. GK-*"
                   class="border rounded px-3 py-2 text-sm font-mono flex-1 min-w-[200px]">
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">+ Tambah</button>
        </form>

        <div class="divide-y border rounded">
            @forelse ($aturan as $a)
                <div class="p-3 flex items-start gap-3 text-sm">
                    <div class="flex-1">
                        <div class="font-mono font-semibold text-gray-800">{{ $a['tampil'] }}
                            <span class="ml-2 font-sans text-xs font-normal {{ $a['jumlah'] ? 'text-gray-500' : 'text-red-600' }}">{{ $a['jumlah'] }} produk aktif</span>
                        </div>
                        @if ($a['contoh']->isNotEmpty())
                            <div class="text-xs text-gray-500 mt-0.5">
                                {{ $a['contoh']->map(fn ($p) => $p->sku . ' (' . $p->name . ')')->implode(', ') }}{{ $a['jumlah'] > $a['contoh']->count() ? ', …' : '' }}
                            </div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('karyawan-virtual.admin.produk-desain.hapus', $kode) }}"
                          onsubmit="return confirm('Hapus {{ $a['tampil'] }} dari daftar?')">
                        @csrf @method('DELETE')
                        <input type="hidden" name="pola" value="{{ $a['pola'] }}">
                        <button class="text-xs text-red-600 hover:underline">Hapus</button>
                    </form>
                </div>
            @empty
                <div class="p-6 text-center text-sm text-gray-400">Daftar kosong — skill Desain pesanan tidak membuat task apa pun.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
