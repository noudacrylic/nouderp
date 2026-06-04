@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Promosi</h1>
    <a href="{{ route('sales.promosi.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Promo</a>
</div>

@if(session('success'))<div class="bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm mb-3">{{ session('success') }}</div>@endif
@if(session('error'))<div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded text-sm mb-3">{{ session('error') }}</div>@endif

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', [
        'name' => 'search',
        'placeholder' => 'Cari nama promo atau kode voucher...',
    ])
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            @foreach($types as $val => $label)
                <option value="{{ $val }}" @selected(request('type')==$val)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="active" @selected(request('status')=='active')>Aktif</option>
            <option value="inactive" @selected(request('status')=='inactive')>Nonaktif</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Nama Promo</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-left">Diskon</th>
                <th class="px-3 py-2 text-left">Voucher</th>
                <th class="px-3 py-2 text-left">Periode</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-right w-40">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse($promos as $p)
                @php
                    $typeBadge = [
                        'item'       => ['Diskon Produk', 'bg-indigo-100 text-indigo-700'],
                        'shipping'   => ['Diskon Ongkir', 'bg-sky-100 text-sky-700'],
                        'cart_total' => ['Total Belanja',  'bg-purple-100 text-purple-700'],
                    ][$p->type] ?? [$p->type, 'bg-gray-100 text-gray-600'];

                    if ($p->type === 'item') {
                        $diskon = $p->discount_type === 'percent'
                            ? rtrim(rtrim(number_format($p->discount_value, 2, ',', '.'), '0'), ',') . '%'
                            : 'Rp ' . number_format($p->discount_value, 0, ',', '.') . '/unit';
                        $scope = $p->applies_to_all ? 'semua produk' : ($p->products_count . ' produk');
                        $diskon .= ' · ' . $scope;
                    } else {
                        $diskon = $p->tiers_count . ' tingkat';
                    }
                @endphp
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('sales.promosi.edit', $p->id) }}">
                    <td class="px-3 py-2 font-medium">{{ $p->name }}</td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-[11px] {{ $typeBadge[1] }}">{{ $typeBadge[0] }}</span>
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ $diskon }}</td>
                    <td class="px-3 py-2">
                        @if($p->is_voucher)
                            <span class="font-mono text-xs">{{ $p->voucher_code }}</span>
                            @include('erp.purchasing._partials.copy-btn', ['value' => $p->voucher_code])
                        @else
                            <span class="text-gray-400 text-xs">otomatis</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-gray-600 text-xs">
                        {{ $p->starts_at ? $p->starts_at->format('d M Y') : '—' }} s/d
                        {{ $p->ends_at ? $p->ends_at->format('d M Y') : '∞' }}
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs uppercase {{ $p->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' }}">
                            {{ $p->is_active ? 'Aktif' : 'Nonaktif' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-1 flex-row-reverse">
                            <a href="{{ route('sales.promosi.edit', $p->id) }}" class="bg-amber-500 text-white px-2 py-1 rounded text-xs">Edit</a>
                            <form method="POST" action="{{ route('sales.promosi.destroy', $p->id) }}" onsubmit="return confirm('Hapus promo {{ $p->name }}?')">
                                @csrf @method('DELETE')
                                <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada promosi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
