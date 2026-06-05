@extends('layouts.erp')

@section('content')
@php
    $isStock = $type === 'stock';
    $heading = $isStock ? 'Otomasi Stok' : 'Otomasi Pesanan';
    $intro = $isStock
        ? 'Saat stok produk turun mencapai minimum (kolom Min Stock di master produk), task otomatis dibuat ke assignee. Tidak akan duplikat selama task masih open.'
        : 'Saat ada Sales Order baru yang berisi produk di rule ini, task otomatis dibuat ke assignee dengan referensi SO.';
@endphp

@include('erp.tasks.automation._subtabs', ['current' => $isStock ? 'stok' : 'pesanan'])

<div class="flex items-center justify-between mb-3">
    <div>
        <h1 class="text-lg font-semibold">{{ $heading }}</h1>
        <p class="text-xs text-gray-500 mt-1 max-w-3xl">{{ $intro }}</p>
    </div>
    <div class="flex gap-2">
        @if($isStock)
            <form method="POST" action="{{ route('tasks.automation.rules.check-stock', ['automationType' => 'stock']) }}">
                @csrf
                <button type="submit" class="border border-gray-300 text-gray-700 px-3 py-2 rounded text-sm hover:bg-gray-50" title="Scan semua rule sekarang">
                    Cek Stok Sekarang
                </button>
            </form>
        @endif
        <a href="{{ route('tasks.automation.rules.create', ['automationType' => $type]) }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Rule</a>
    </div>
</div>

@if(session('success'))
    <div class="mb-3 bg-green-50 border border-green-200 text-green-700 px-3 py-2 rounded text-sm">{{ session('success') }}</div>
@endif

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Produk</th>
                @if($isStock)
                    <th class="px-3 py-2 text-right">Stok / Min</th>
                @endif
                <th class="px-3 py-2 text-left">Assignee</th>
                <th class="px-3 py-2 text-left">Kategori</th>
                <th class="px-3 py-2 text-center">Prioritas</th>
                <th class="px-3 py-2 text-left">Terakhir Trigger</th>
                <th class="px-3 py-2 text-center">Aktif</th>
                <th class="px-3 py-2 text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($rules as $r)
            @php
                // Stok fisik live = SUM(qty_on_hand) dari product_stocks, dimuat lewat withSum.
                $stock = (float) ($r->product->stok_fisik ?? 0);
                $min   = (float) ($r->product->min_stock ?? 0);
                $low   = $isStock && $min > 0 && $stock <= $min;
            @endphp
            <tr class="border-b">
                <td class="px-3 py-2">
                    @if($r->product)
                        <div class="font-medium">{{ $r->product->name }}</div>
                        <div class="text-xs text-gray-400">{{ $r->product->sku }}</div>
                    @else
                        <span class="text-gray-400">— Produk tidak ditemukan —</span>
                    @endif
                </td>
                @if($isStock)
                    <td class="px-3 py-2 text-right tabular-nums {{ $low ? 'text-red-600 font-semibold' : '' }}">
                        {{ rtrim(rtrim(number_format($stock, 4, '.', ''), '0'), '.') }}
                        <span class="text-gray-400 text-xs">/</span>
                        {{ $min > 0 ? rtrim(rtrim(number_format($min, 4, '.', ''), '0'), '.') : '—' }}
                    </td>
                @endif
                <td class="px-3 py-2">{{ $r->assignee->name ?? '—' }}</td>
                <td class="px-3 py-2">
                    @if($r->category)
                        <span class="px-2 py-0.5 rounded text-xs text-white" style="background: {{ $r->category->color }}">{{ $r->category->name }}</span>
                    @else
                        <span class="text-gray-400">—</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-center">
                    @php
                        $pClass = match($r->priority) {
                            'high'   => 'bg-red-100 text-red-700',
                            'low'    => 'bg-gray-100 text-gray-600',
                            default  => 'bg-blue-100 text-blue-700',
                        };
                    @endphp
                    <span class="px-2 py-0.5 rounded text-xs {{ $pClass }}">{{ ucfirst($r->priority) }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-gray-500">{{ $r->last_triggered_at ? $r->last_triggered_at->format('d M Y H:i') : '—' }}</td>
                <td class="px-3 py-2 text-center">
                    @if($r->is_active)
                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Aktif</span>
                    @else
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-500">Nonaktif</span>
                    @endif
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                    <a href="{{ route('tasks.automation.rules.edit', ['automationType' => $type, 'rule' => $r->id]) }}" class="bg-blue-600 text-white px-2 py-1 rounded text-xs">Edit</a>
                    <form method="POST" action="{{ route('tasks.automation.rules.destroy', ['automationType' => $type, 'rule' => $r->id]) }}" class="inline" onsubmit="return confirm('Hapus rule ini?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ $isStock ? 8 : 7 }}" class="px-3 py-6 text-center text-gray-400">Belum ada rule.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($rules->hasPages())
    <div class="mt-3">{{ $rules->links() }}</div>
@endif
@endsection
