@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Riwayat Saldo Awal</h1>
    <div class="flex gap-2 flex-wrap">
        <a href="{{ route('accounts.opening-balance.products.template') }}"
           class="bg-gray-700 hover:bg-gray-800 text-white px-3 py-2 rounded text-sm" title="Template Excel saldo awal produk">
            ⬇ Template Produk
        </a>
        <form method="POST" action="{{ route('accounts.opening-balance.products.import') }}"
              enctype="multipart/form-data" class="inline-flex gap-1 items-center">
            @csrf
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                   class="text-xs file:mr-2 file:py-1.5 file:px-2 file:rounded file:border-0 file:bg-amber-100 file:text-amber-800 hover:file:bg-amber-200">
            <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded text-sm">📤 Import Produk</button>
        </form>
        <a href="{{ route('accounts.opening-balance.create') }}"
           class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">+ Saldo Awal Akun</a>
    </div>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari deskripsi / akun / SKU / gudang...'])
    @include('erp.purchasing._partials.date-range')
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe Data</label>
        <select name="tipe" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="akun"   @selected(request('tipe')=='akun')>Akun (Kas / Bank / Aset)</option>
            <option value="produk" @selected(request('tipe')=='produk')>Produk (Stok)</option>
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Arah</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="increase" @selected(request('type')=='increase')>Tambah (Debit)</option>
            <option value="decrease" @selected(request('type')=='decrease')>Kurangi (Kredit)</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Akun / Produk</th>
                <th class="px-3 py-2 text-left">Gudang</th>
                <th class="px-3 py-2 text-right">Qty</th>
                <th class="px-3 py-2 text-right">Biaya</th>
                <th class="px-3 py-2 text-right">Tambah (Debit)</th>
                <th class="px-3 py-2 text-right">Kurangi (Kredit)</th>
                <th class="px-3 py-2 text-left">Deskripsi</th>
                <th class="px-3 py-2 text-right w-36">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($journals as $row)
                @php
                    $tipeCls = $row->kind === 'produk'
                        ? 'bg-amber-100 text-amber-700'
                        : 'bg-sky-100 text-sky-700';
                    $tipeLabel = $row->kind === 'produk' ? 'PRODUK' : 'AKUN';
                @endphp
                <tr class="border-b hover:bg-blue-50 {{ $row->edit_url ? 'cursor-pointer' : '' }}"
                    @if($row->edit_url) data-href="{{ $row->edit_url }}" @endif>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $row->date?->format('d M Y') }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $tipeCls }}">{{ $tipeLabel }}</span>
                    </td>
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $row->item_title }}</div>
                        @if($row->item_sub)
                            <div class="text-xs text-gray-500">{{ $row->item_sub }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ $row->detail ?: '-' }}</td>
                    <td class="px-3 py-2 text-right text-gray-700">
                        {{ $row->qty !== null ? rtrim(rtrim(number_format($row->qty, 4, ',', '.'), '0'), ',') : '-' }}
                    </td>
                    <td class="px-3 py-2 text-right text-gray-700">
                        {{ $row->cost !== null ? number_format($row->cost, 0, ',', '.') : '-' }}
                    </td>
                    <td class="px-3 py-2 text-right text-green-700 font-medium">
                        {{ $row->debit > 0 ? number_format($row->debit, 0, ',', '.') : '-' }}
                    </td>
                    <td class="px-3 py-2 text-right text-red-700 font-medium">
                        {{ $row->credit > 0 ? number_format($row->credit, 0, ',', '.') : '-' }}
                    </td>
                    <td class="px-3 py-2 text-gray-600 max-w-xs truncate" title="{{ $row->description }}">{{ $row->description }}</td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-nowrap">
                            @if($row->edit_url)
                                <a href="{{ $row->edit_url }}"
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-2 py-1 rounded text-xs">{{ $row->edit_label }}</a>
                            @endif
                            @if(!empty($row->delete_url))
                                <form method="POST" action="{{ $row->delete_url }}" class="inline"
                                      onsubmit="return confirm('Hapus saldo awal {{ $row->item_title }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                            @if(!empty($row->ledger_url))
                                <a href="{{ $row->ledger_url }}"
                                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-2 py-1 rounded text-xs">Ledger</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-400">Belum ada saldo awal tercatat.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
