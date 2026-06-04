@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Bill of Materials (BOM)</h1>
    <div class="flex gap-2">
        <form method="POST" action="{{ route('production.boms.run-auto') }}"
              onsubmit="return confirm('Jalankan auto-produksi sekarang? Sistem akan memeriksa semua BOM dengan auto-produksi aktif dan membuat order produksi untuk produk yang stoknya menipis.')">
            @csrf
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">
                ⚡ Jalankan Auto Produksi
            </button>
        </form>
        <a href="{{ route('production.boms.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Buat BOM</a>
    </div>
</div>

@if(session('info'))
    <div class="bg-blue-50 border border-blue-200 text-blue-700 text-sm rounded p-3 mb-3">{{ session('info') }}</div>
@endif

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari nomor atau nama BOM...'])
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Nomor BOM</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-center">Material</th>
                <th class="px-3 py-2 text-center">Output</th>
                <th class="px-3 py-2 text-center">Langkah</th>
                <th class="px-3 py-2 text-center">Skor</th>
                <th class="px-3 py-2 text-center">Auto</th>
                <th class="px-3 py-2 text-center w-40">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($boms as $bom)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('production.boms.edit', $bom->id) }}">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">
                        {{ $bom->bom_number }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $bom->bom_number])
                    </td>
                    <td class="px-3 py-2">{{ $bom->name }}</td>
                    <td class="px-3 py-2 text-center">{{ $bom->materials_count }}</td>
                    <td class="px-3 py-2 text-center">{{ $bom->outputs_count }}</td>
                    <td class="px-3 py-2 text-center">{{ $bom->steps_count }}</td>
                    <td class="px-3 py-2 text-center font-medium">{{ number_format($bom->score, 2) }}</td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <form method="POST" action="{{ route('production.boms.toggle-auto', $bom->id) }}" class="inline">
                            @csrf
                            <button type="submit"
                                    title="{{ $bom->auto_production ? 'Klik untuk nonaktifkan auto-produksi' : 'Klik untuk aktifkan auto-produksi' }}"
                                    class="px-2 py-0.5 rounded text-xs uppercase {{ $bom->auto_production ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-400' }}">
                                {{ $bom->auto_production ? 'ON' : 'OFF' }}
                            </button>
                        </form>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            <form method="POST" action="{{ route('production.boms.clone', $bom->id) }}" class="inline">
                                @csrf
                                <button class="bg-gray-600 text-white px-2 py-1 rounded text-xs">Salin</button>
                            </form>
                            <form method="POST" action="{{ route('production.boms.destroy', $bom->id) }}"
                                  onsubmit="return confirm('Hapus BOM {{ $bom->bom_number }}?')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada BOM.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $boms->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
