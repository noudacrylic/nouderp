@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Disposisi Aset Tetap</h1>
    <a href="{{ route('fixed-assets.disposals.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Disposisi</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="No / kode aset / nama..." class="border rounded px-2 py-1.5 w-72">
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
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="disposal_type" class="border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach(['sale'=>'Jual','damage'=>'Rusak','loss'=>'Hilang','donation'=>'Donasi','scrap'=>'Scrap'] as $v=>$l)
                <option value="{{ $v }}" @selected(request('disposal_type')==$v)>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dari</label>
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="border rounded px-2 py-1.5">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">s/d</label>
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="border rounded px-2 py-1.5">
    </div>
    <button class="bg-gray-700 text-white px-3 py-1.5 rounded text-sm">Filter</button>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">No</th>
                <th class="px-3 py-2 text-left">Tanggal</th>
                <th class="px-3 py-2 text-left">Aset</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-right">Proceeds</th>
                <th class="px-3 py-2 text-right">Nilai Buku</th>
                <th class="px-3 py-2 text-right">Gain/Loss</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-72">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($disposals as $d)
                @php
                    [$statusLabel, $statusCls] = match($d->status) {
                        'draft'  => ['Draf', 'bg-yellow-100 text-yellow-700'],
                        'posted' => ['Diposting', 'bg-green-100 text-green-700'],
                        'void'   => ['Void', 'bg-red-100 text-red-700'],
                        default  => [ucfirst($d->status), 'bg-gray-100 text-gray-500'],
                    };
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('fixed-assets.disposals.show', $d->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $d->disposal_number }}</td>
                    <td class="px-3 py-2">{{ optional($d->disposal_date)->format('d M Y') }}</td>
                    <td class="px-3 py-2">{{ $d->asset->asset_code ?? '-' }} — {{ $d->asset->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ ucfirst($d->disposal_type) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($d->proceeds_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format($d->book_value_at_disposal, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right {{ $d->gain_loss_amount >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ number_format($d->gain_loss_amount, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-center"><span class="px-2 py-0.5 rounded text-xs uppercase {{ $statusCls }}">{{ $statusLabel }}</span></td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse flex-wrap">
                            @if($d->status === 'draft')
                                <form method="POST" action="{{ route('fixed-assets.disposals.post', $d->id) }}" onsubmit="return confirm('Post disposisi?')">@csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Post</button>
                                </form>
                                <form method="POST" action="{{ route('fixed-assets.disposals.destroy', $d->id) }}" onsubmit="return confirm('Hapus disposisi draf?')">@csrf @method('DELETE')
                                    <button class="bg-gray-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                                <a href="{{ route('fixed-assets.disposals.edit', $d->id) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                            @endif
                            @if($d->canBeVoided())
                                <form method="POST" action="{{ route('fixed-assets.disposals.void', $d->id) }}" onsubmit="return confirm('Void disposisi? Aset akan kembali aktif.')">@csrf
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Void</button>
                                </form>
                            @endif
                            <a href="{{ route('fixed-assets.disposals.print', $d->id) }}" class="bg-gray-700 text-white px-2 py-1 rounded text-xs">Cetak</a>
                            <a href="{{ route('fixed-assets.disposals.show', $d->id) }}" class="bg-gray-500 text-white px-2 py-1 rounded text-xs">Rincian</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">Belum ada disposisi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3">{{ $disposals->links() }}</div>

<script>
document.querySelectorAll('tr[data-href]').forEach(r => r.addEventListener('click', () => location.href = r.dataset.href));
</script>
@endsection
