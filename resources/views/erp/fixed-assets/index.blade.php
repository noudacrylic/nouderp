@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4 gap-3">
    <h1 class="text-lg font-semibold">Daftar Aset Tetap</h1>
    <div class="flex items-center gap-3">
        <div class="text-right">
            <div class="text-[11px] text-gray-500 uppercase tracking-wide">Penyusutan / bulan</div>
            <div class="text-base font-semibold text-gray-800">Rp {{ number_format($monthlyDepreciationTotal ?? 0, 0, ',', '.') }}</div>
        </div>
        <a href="{{ route('fixed-assets.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Aset</a>
    </div>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Kode / nama / serial...'])
    @include('erp.purchasing._partials.date-range', ['labelFrom' => 'Dari Tanggal', 'labelTo' => 's/d'])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft'=>'Draf','active'=>'Aktif','disposed'=>'Disposed','voided'=>'Void'] as $v=>$l)
                <option value="{{ $v }}" @selected(request('status')==$v)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Kategori</label>
        <select name="category_id" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($categories as $c)
                <option value="{{ $c->id }}" @selected(request('category_id')==$c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Gudang</label>
        <select name="warehouse_id" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($warehouses as $w)
                <option value="{{ $w->id }}" @selected(request('warehouse_id')==$w->id)>{{ $w->name }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Kode</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Kategori</th>
                <th class="px-3 py-2 text-left">Gudang</th>
                <th class="px-3 py-2 text-left">Tgl Perolehan</th>
                <th class="px-3 py-2 text-right">Biaya</th>
                <th class="px-3 py-2 text-right">Akumulasi</th>
                <th class="px-3 py-2 text-right">Nilai Buku</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($assets as $asset)
                @php
                    [$statusLabel, $statusCls] = match($asset->status) {
                        'draft'    => ['Draf', 'bg-yellow-100 text-yellow-700'],
                        'active'   => ['Aktif', 'bg-green-100 text-green-700'],
                        'disposed' => ['Disposed', 'bg-gray-200 text-gray-600'],
                        'voided'   => ['Void', 'bg-red-100 text-red-700'],
                        default    => [ucfirst($asset->status), 'bg-gray-100 text-gray-500'],
                    };
                    $rowHref = $asset->canBeEdited()
                        ? route('fixed-assets.edit', $asset->id)
                        : route('fixed-assets.show', $asset->id);
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ $rowHref }}">
                    <td class="px-3 py-2 font-medium">{{ $asset->asset_code }}</td>
                    <td class="px-3 py-2">{{ $asset->name }}</td>
                    <td class="px-3 py-2">{{ $asset->category->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $asset->warehouse->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ optional($asset->acquisition_date)->format('d M Y') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($asset->acquisition_cost, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($asset->accumulated_depreciation, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right font-medium">{{ number_format($asset->current_book_value, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($asset->status === 'draft' && $asset->canBePosted())
                                <form method="POST" action="{{ route('fixed-assets.post', $asset->id) }}" onsubmit="return confirm('Post aset ini?')">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                            @endif
                            @if($asset->status !== 'draft' && $asset->canBeVoided())
                                <form method="POST" action="{{ route('fixed-assets.void', $asset->id) }}" onsubmit="return confirm('Void aset ini?')">
                                    @csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                            @if($asset->status === 'active' && $asset->canBeDisposed())
                                <a href="{{ route('fixed-assets.disposals.create', ['asset_id' => $asset->id]) }}" class="bg-orange-600 text-white px-2 py-1 rounded text-xs">Disposisi</a>
                                <a href="{{ route('fixed-assets.transfers.create', ['asset_id' => $asset->id]) }}" class="bg-blue-500 text-white px-2 py-1 rounded text-xs">Transfer</a>
                            @endif
                            @if($asset->status === 'draft')
                                <form method="POST" action="{{ route('fixed-assets.destroy', $asset->id) }}" onsubmit="return confirm('Hapus aset draf ini?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-gray-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-6 text-center text-gray-500">Belum ada data aset.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $assets->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
