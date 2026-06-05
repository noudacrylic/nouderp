@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Transfer Aset Tetap</h1>
    <a href="{{ route('fixed-assets.transfers.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Transfer</a>
</div>

@if(session('success')) <div class="bg-green-100 border border-green-300 text-green-700 px-3 py-2 rounded mb-3 text-sm">{{ session('success') }}</div> @endif
@if(session('error')) <div class="bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded mb-3 text-sm">{{ session('error') }}</div> @endif

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="No transfer / kode aset / nama..." class="border rounded px-2 py-1.5 w-72">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['draft'=>'Draf','posted'=>'Diposting','void'=>'Void'] as $v=>$l)
                <option value="{{ $v }}" @selected(request('status')==$v)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No Transfer</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Aset</th>
                <th class="px-3 py-2 text-left">Dari</th>
                <th class="px-3 py-2 text-left">Ke</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transfers as $tr)
                @php
                    [$statusLabel, $statusCls] = match($tr->status) {
                        'draft'  => ['Draf', 'bg-yellow-100 text-yellow-700'],
                        'posted' => ['Diposting', 'bg-green-100 text-green-700'],
                        'void'   => ['Void', 'bg-red-100 text-red-700'],
                        default  => [ucfirst($tr->status), 'bg-gray-100 text-gray-500'],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('fixed-assets.transfers.show', $tr->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $tr->transfer_number }}</td>
                    <td class="px-3 py-2">{{ optional($tr->transfer_date)->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $tr->asset->asset_code ?? '-' }} — {{ $tr->asset->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $tr->fromWarehouse->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $tr->toWarehouse->name ?? '-' }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($tr->status === 'draft')
                                <form method="POST" action="{{ route('fixed-assets.transfers.post', $tr->id) }}" onsubmit="return confirm('Post transfer ini?')">@csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <form method="POST" action="{{ route('fixed-assets.transfers.destroy', $tr->id) }}" onsubmit="return confirm('Hapus transfer draf?')">@csrf @method('DELETE')
                                    <button class="bg-gray-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                                <a href="{{ route('fixed-assets.transfers.edit', $tr->id) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                            @endif
                            @if($tr->canBeVoided())
                                <form method="POST" action="{{ route('fixed-assets.transfers.void', $tr->id) }}" onsubmit="return confirm('Void transfer ini? Aset akan kembali ke gudang asal.')">@csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                            <a href="{{ route('fixed-assets.transfers.show', $tr->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Rincian</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Belum ada transfer.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $transfers->links() }}</div>

<script>
document.querySelectorAll('tr[data-href]').forEach(r => r.addEventListener('click', () => location.href = r.dataset.href));
</script>
@endsection
