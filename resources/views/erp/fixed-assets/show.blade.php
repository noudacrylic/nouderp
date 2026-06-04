@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <div>
        <h1 class="text-lg font-semibold">{{ $asset->asset_code }} — {{ $asset->name }}</h1>
        @php
            [$statusLabel, $statusCls] = match($asset->status) {
                'draft'    => ['Draft', 'bg-yellow-100 text-yellow-700'],
                'active'   => ['Aktif', 'bg-green-100 text-green-700'],
                'disposed' => ['Disposed', 'bg-gray-200 text-gray-600'],
                'voided'   => ['Void', 'bg-red-100 text-red-700'],
                default    => [ucfirst($asset->status), 'bg-gray-100 text-gray-500'],
            };
        @endphp
        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span>
    </div>
    <div class="flex gap-1 flex-row-reverse flex-wrap">
        @if($asset->canBeVoided())
            <form method="POST" action="{{ route('fixed-assets.void', $asset->id) }}" onsubmit="return confirm('Void aset ini?')">@csrf
                <button class="bg-red-600 text-white px-3 py-2 rounded text-sm">Void</button>
            </form>
        @endif
        @if($asset->status === 'active' && $asset->canBeDisposed())
            <a href="{{ route('fixed-assets.disposals.create', ['asset_id' => $asset->id]) }}" class="bg-orange-600 text-white px-3 py-2 rounded text-sm">Disposisi</a>
            <a href="{{ route('fixed-assets.transfers.create', ['asset_id' => $asset->id]) }}" class="bg-blue-500 text-white px-3 py-2 rounded text-sm">Transfer</a>
        @endif
        @if($asset->canBeEdited())
            <a href="{{ route('fixed-assets.edit', $asset->id) }}" class="bg-gray-700 text-white px-3 py-2 rounded text-sm">Edit</a>
        @endif
        @if($asset->status === 'draft' && $asset->canBePosted())
            <form method="POST" action="{{ route('fixed-assets.post', $asset->id) }}" onsubmit="return confirm('Post aset ini?')">@csrf
                <button class="bg-green-600 text-white px-3 py-2 rounded text-sm">Post</button>
            </form>
        @endif
        <a href="{{ route('fixed-assets.index') }}" class="text-gray-500 text-sm underline self-center">← Kembali</a>
    </div>
</div>

@if(session('success')) <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded mb-3 text-sm">{{ session('success') }}</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<div class="grid grid-cols-3 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 col-span-2">
        <h2 class="font-semibold mb-3">Detail Aset</h2>
        <table class="w-full text-sm">
            <tbody>
                <tr><td class="py-1 text-gray-500 w-48">Kategori</td><td class="py-1">{{ $asset->category->name ?? '-' }}</td></tr>
                <tr><td class="py-1 text-gray-500">Gudang</td><td class="py-1">{{ $asset->warehouse->name ?? '-' }}</td></tr>
                <tr><td class="py-1 text-gray-500">Serial Number</td><td class="py-1">{{ $asset->serial_number ?? '-' }}</td></tr>
                <tr><td class="py-1 text-gray-500">PIC</td><td class="py-1">{{ $asset->responsible_person ?? '-' }}</td></tr>
                <tr><td class="py-1 text-gray-500">Tanggal Perolehan</td><td class="py-1">{{ optional($asset->acquisition_date)->format('d M Y') }}</td></tr>
                <tr><td class="py-1 text-gray-500">Sumber</td><td class="py-1">
                    @if($asset->source_type === 'purchase' && $asset->sourceInvoice)
                        Pembelian — <a href="{{ route('purchasing.invoices.show', $asset->source_invoice_id) }}" class="text-blue-600 underline">{{ $asset->sourceInvoice->invoice_number }}</a>
                    @else Manual @endif
                </td></tr>
                <tr><td class="py-1 text-gray-500">Disusutkan?</td><td class="py-1">{{ $asset->is_depreciable ? 'Ya' : 'Tidak' }}</td></tr>
                @if($asset->is_depreciable)
                    <tr><td class="py-1 text-gray-500">Umur Ekonomis</td><td class="py-1">{{ $asset->useful_life_months }} bulan</td></tr>
                    <tr><td class="py-1 text-gray-500">Nilai Residu</td><td class="py-1">{{ number_format($asset->salvage_value, 2, ',', '.') }}</td></tr>
                    <tr><td class="py-1 text-gray-500">Mulai Disusutkan</td><td class="py-1">{{ optional($asset->depreciation_start_date)->format('d M Y') ?? '-' }}</td></tr>
                    <tr><td class="py-1 text-gray-500">Disusutkan Sampai</td><td class="py-1">{{ optional($asset->last_depreciation_date)->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="py-1 text-gray-500">Bulan Tersisa</td><td class="py-1">{{ max(0, (int)$asset->useful_life_months - (int)$asset->months_depreciated) }}</td></tr>
                @endif
                @if($asset->notes)
                    <tr><td class="py-1 text-gray-500 align-top">Catatan</td><td class="py-1 whitespace-pre-line">{{ $asset->notes }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold mb-3">Ringkasan Nilai</h2>
        <div class="text-sm">
            <div class="flex justify-between py-1 border-b"><span class="text-gray-500">Harga Perolehan</span><span class="font-medium">{{ number_format($asset->acquisition_cost, 2, ',', '.') }}</span></div>
            <div class="flex justify-between py-1 border-b"><span class="text-gray-500">Akumulasi Penyusutan</span><span class="font-medium">{{ number_format($asset->accumulated_depreciation, 2, ',', '.') }}</span></div>
            <div class="flex justify-between py-1 border-b"><span class="text-gray-500">Nilai Buku</span><span class="font-bold text-green-700">{{ number_format($asset->current_book_value, 2, ',', '.') }}</span></div>
            @if($asset->is_depreciable && $asset->status === 'active')
                <div class="flex justify-between py-1 mt-2 text-blue-600"><span>Penyusutan / bulan</span><span>{{ number_format($asset->monthlyDepreciation(), 2, ',', '.') }}</span></div>
            @endif
        </div>
    </div>
</div>

<div class="bg-white rounded shadow p-4 mb-4">
    <h2 class="font-semibold mb-3">Riwayat Penyusutan</h2>
    @if($asset->depreciations->isEmpty())
        <div class="text-gray-500 text-sm">Belum ada penyusutan.</div>
    @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">Periode</th>
                    <th class="px-3 py-2 text-left">Tanggal</th>
                    <th class="px-3 py-2 text-right">Amount</th>
                    <th class="px-3 py-2 text-right">Akumulasi Setelah</th>
                    <th class="px-3 py-2 text-right">Nilai Buku Setelah</th>
                    <th class="px-3 py-2 text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($asset->depreciations->sortBy('depreciation_date') as $d)
                    <tr class="border-b">
                        <td class="px-3 py-2">{{ $d->period_year }}-{{ str_pad($d->period_month, 2, '0', STR_PAD_LEFT) }}</td>
                        <td class="px-3 py-2">{{ optional($d->depreciation_date)->format('d M Y') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($d->amount, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($d->accumulated_after, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($d->book_value_after, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-center">
                            <span class="px-2 py-0.5 rounded text-xs uppercase {{ $d->status === 'posted' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $d->status }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="grid grid-cols-2 gap-4">
    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold mb-3">Riwayat Transfer</h2>
        @if($asset->transfers->isEmpty())
            <div class="text-gray-500 text-sm">Belum ada transfer.</div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b text-gray-600"><tr>
                    <th class="px-3 py-2 text-left">No</th>
                    <th class="px-3 py-2 text-left">Tanggal</th>
                    <th class="px-3 py-2 text-left">Dari → Ke</th>
                    <th class="px-3 py-2 text-center">Status</th>
                </tr></thead>
                <tbody>
                    @foreach($asset->transfers as $t)
                        <tr class="border-b">
                            <td class="px-3 py-2"><a href="{{ route('fixed-assets.transfers.show', $t->id) }}" class="text-blue-600 underline">{{ $t->transfer_number }}</a></td>
                            <td class="px-3 py-2">{{ optional($t->transfer_date)->format('d M Y') }}</td>
                            <td class="px-3 py-2">{{ $t->fromWarehouse->name ?? '-' }} → {{ $t->toWarehouse->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-600">{{ $t->status }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="bg-white rounded shadow p-4">
        <h2 class="font-semibold mb-3">Riwayat Disposisi</h2>
        @if($asset->disposals->isEmpty())
            <div class="text-gray-500 text-sm">Belum ada disposisi.</div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b text-gray-600"><tr>
                    <th class="px-3 py-2 text-left">No</th>
                    <th class="px-3 py-2 text-left">Tanggal</th>
                    <th class="px-3 py-2 text-left">Tipe</th>
                    <th class="px-3 py-2 text-right">Proceeds</th>
                    <th class="px-3 py-2 text-right">Gain/Loss</th>
                    <th class="px-3 py-2 text-center">Status</th>
                </tr></thead>
                <tbody>
                    @foreach($asset->disposals as $d)
                        <tr class="border-b">
                            <td class="px-3 py-2"><a href="{{ route('fixed-assets.disposals.show', $d->id) }}" class="text-blue-600 underline">{{ $d->disposal_number }}</a></td>
                            <td class="px-3 py-2">{{ optional($d->disposal_date)->format('d M Y') }}</td>
                            <td class="px-3 py-2">{{ ucfirst($d->disposal_type) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($d->proceeds_amount, 2, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right {{ $d->gain_loss_amount >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ number_format($d->gain_loss_amount, 2, ',', '.') }}</td>
                            <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-600">{{ $d->status }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
