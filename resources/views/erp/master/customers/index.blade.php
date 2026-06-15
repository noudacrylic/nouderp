@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Customer</h1>
    <a href="{{ route('customers.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Customer</a>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari kode, nama, telp, atau kota...'])
</form>

@php
    $totalCredit = $customers->sum('credit_balance');
@endphp

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left whitespace-nowrap">Kode</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Telp</th>
                <th class="px-3 py-2 text-left">Kota</th>
                <th class="px-3 py-2 text-left w-28">Tipe</th>
                <th class="px-3 py-2 text-right w-36" title="Saldo Lebih Bayar Customer — bisa dipakai sebagai potongan invoice">Saldo Kredit</th>
                <th class="px-3 py-2 text-center w-20">Status</th>
                <th class="px-3 py-2 text-left w-20">Detail</th>
                <th class="px-3 py-2 text-center w-24">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($customers as $c)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('customers.edit', $c->id) }}">
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ $c->code }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $c->code])
                    </td>
                    <td class="px-3 py-2 font-medium">{{ $c->name }}</td>
                    <td class="px-3 py-2">{{ $c->phone ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $c->city ?? '-' }}</td>
                    <td class="px-3 py-2">
                        @if($c->is_marketplace)
                            <span class="bg-purple-100 text-purple-700 px-2 py-0.5 rounded text-xs uppercase">Marketplace</span>
                        @else
                            <span class="bg-gray-100 text-gray-600 px-2 py-0.5 rounded text-xs uppercase">{{ $c->customer_type ?? 'Regular' }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right {{ $c->credit_balance > 0 ? 'text-green-600 font-semibold' : 'text-gray-400' }}">
                        {{ number_format($c->credit_balance, 0, ',', '.') }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if($c->is_active)
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs">Aktif</span>
                        @else
                            <span class="bg-gray-200 text-gray-600 px-2 py-0.5 rounded text-xs">Nonaktif</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <a href="{{ route('customers.show', $c->id) }}" class="text-gray-600">Detail</a>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            @if(!$c->is_active)
                                <form method="POST" action="{{ route('customers.restore', $c->id) }}" class="inline">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Restore</button>
                                </form>
                            @elseif($c->is_used)
                                <form method="POST" action="{{ route('customers.archive', $c->id) }}" class="inline"
                                      onsubmit="return confirm('Arsipkan customer ini? (nonaktif, tetap tersimpan)')">
                                    @csrf
                                    <button class="bg-yellow-500 text-white px-2 py-1 rounded text-xs" title="Sudah dipakai transaksi — hanya bisa diarsipkan">Archive</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('customers.destroy', $c->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus customer ini secara permanen? Tidak bisa dibatalkan.')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada customer.</td></tr>
            @endforelse
        </tbody>
        @if($customers->count())
            <tfoot class="bg-gray-50 border-t font-bold text-gray-700">
                <tr>
                    <td colspan="5" class="px-3 py-2 text-right uppercase tracking-widest text-[10px]">Total</td>
                    <td class="px-3 py-2 text-right text-green-700">{{ number_format($totalCredit, 0, ',', '.') }}</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

@if($customers instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $customers->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')
@endsection
