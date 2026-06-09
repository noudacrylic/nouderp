@extends('layouts.erp')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white shadow rounded-lg border p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-2">Jasa Kirim</h2>
        <p class="text-sm text-gray-500 mb-6">
            Daftar jasa kirim <b>manual</b> — ekspedisi yang tidak dijangkau API (Biteship/KiriminAja),
            misalnya cargo atau ekspedisi lokal. Kurir di sini muncul di kartu <b>Pengiriman</b> pada
            Sales Order &amp; Faktur, berdampingan dengan kurir API. Saat dipilih, <b>ongkirnya diisi manual</b>.
        </p>

        @if(session('success'))
            <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded text-sm mb-4">
                {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-4">
                @foreach ($errors->all() as $err)<div>• {{ $err }}</div>@endforeach
            </div>
        @endif

        {{-- Tambah jasa kirim --}}
        <form method="POST" action="{{ route('settings.shipping-couriers.store') }}" class="flex items-end gap-2 mb-6">
            @csrf
            <div class="flex-1">
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nama Jasa Kirim</label>
                <input type="text" name="name" value="{{ old('name') }}" required maxlength="100"
                    placeholder="mis. J&T Cargo, Indah Cargo, Ekspedisi Lokal…"
                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm">
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold whitespace-nowrap">
                + Tambah
            </button>
        </form>

        {{-- Daftar --}}
        @if($couriers->isEmpty())
            <div class="text-center text-gray-400 text-sm py-8 border border-dashed border-gray-200 rounded-lg">
                Belum ada jasa kirim manual. Tambahkan di atas.
            </div>
        @else
            <div class="border border-gray-100 rounded-lg divide-y divide-gray-100">
                @foreach($couriers as $c)
                    <div class="flex items-center gap-3 px-3 py-2.5">
                        <form method="POST" action="{{ route('settings.shipping-couriers.update', $c) }}" class="flex items-center gap-2 flex-1 min-w-0">
                            @csrf @method('PUT')
                            <input type="hidden" name="is_active" value="{{ $c->is_active ? 1 : 0 }}">
                            <input type="text" name="name" value="{{ $c->name }}" required maxlength="100"
                                class="form-control flex-1 min-w-0 border border-transparent hover:border-gray-200 focus:border-gray-300 rounded-lg px-2 py-1.5 text-sm {{ $c->is_active ? 'text-gray-800' : 'text-gray-400 line-through' }}">
                            <button type="submit" class="text-[11px] px-2 py-1 border border-gray-300 rounded text-gray-600 hover:bg-gray-100 whitespace-nowrap">Simpan</button>
                        </form>

                        @if($c->is_active)
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-green-50 text-green-600 border border-green-200">Aktif</span>
                        @else
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-400 border border-gray-200">Nonaktif</span>
                        @endif

                        <form method="POST" action="{{ route('settings.shipping-couriers.toggle', $c) }}">
                            @csrf
                            <button type="submit" class="text-[11px] px-2 py-1 border border-gray-300 rounded text-gray-600 hover:bg-gray-100 whitespace-nowrap">
                                {{ $c->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('settings.shipping-couriers.destroy', $c) }}"
                              onsubmit="return confirm('Hapus jasa kirim &quot;{{ $c->name }}&quot;?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-[11px] px-2 py-1 border border-red-200 rounded text-red-600 hover:bg-red-50 whitespace-nowrap">Hapus</button>
                        </form>
                    </div>
                @endforeach
            </div>
            <p class="text-[11px] text-gray-400 mt-2">Jasa kirim <b>nonaktif</b> tidak muncul di form Pengiriman, tapi data transaksi lama tetap aman.</p>
        @endif
    </div>
</div>
@endsection
