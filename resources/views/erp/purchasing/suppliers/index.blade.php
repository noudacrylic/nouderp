@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Pemasok</h1>
    <a href="{{ route('purchasing.suppliers.create') }}" class="bg-blue-600 text-white px-3 py-2 rounded text-sm">+ Tambah Pemasok</a>
</div>


<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['placeholder' => 'Cari kode, nama, telp, atau kota...'])
    @include('erp._partials.per-page-select')
</form>

{{-- $totalAp/$totalDp/$totalOverpay dihitung di controller atas SELURUH pemasok yang cocok
     filter (bukan hanya halaman ini), supaya total footer tetap benar walau daftar dipaginasi. --}}

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left whitespace-nowrap">Kode</th>
                <th class="px-3 py-2 text-left">Nama</th>
                <th class="px-3 py-2 text-left">Telp</th>
                <th class="px-3 py-2 text-left">Kota</th>
                <th class="px-3 py-2 text-right w-20">Term (hari)</th>
                <th class="px-3 py-2 text-right w-32" title="Hutang Usaha (AP/2101) — outstanding faktur diposting">Hutang</th>
                <th class="px-3 py-2 text-right w-32" title="Saldo Uang Muka Pemasok (1107) — auto-apply ke faktur">DP (1107)</th>
                <th class="px-3 py-2 text-right w-32" title="Saldo Piutang Lebih Bayar Pemasok (1108) — bisa dipakai via Gunakan Saldo">Piutang (1108)</th>
                <th class="px-3 py-2 text-center w-20">Status</th>
                <th class="px-3 py-2 text-left w-20">Rincian</th>
                <th class="px-3 py-2 text-center w-24">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($suppliers as $s)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('purchasing.suppliers.edit', $s->id) }}">
                    <td class="px-3 py-2 whitespace-nowrap">
                        {{ $s->code }}
                        @include('erp.purchasing._partials.copy-btn', ['value' => $s->code])
                    </td>
                    <td class="px-3 py-2 font-medium">{{ $s->name }}</td>
                    <td class="px-3 py-2">{{ $s->phone ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $s->city ?? '-' }}</td>
                    <td class="px-3 py-2 text-right">{{ $s->payment_term_days }}</td>
                    <td class="px-3 py-2 text-right {{ $s->ap_outstanding > 0 ? 'text-red-600 font-semibold' : 'text-gray-400' }}">
                        {{ number_format($s->ap_outstanding, 0, ',', '.') }}
                    </td>
                    <td class="px-3 py-2 text-right {{ $s->dp_balance > 0 ? 'text-blue-600 font-semibold' : 'text-gray-400' }}">
                        {{ number_format($s->dp_balance, 0, ',', '.') }}
                    </td>
                    <td class="px-3 py-2 text-right {{ $s->overpay_balance > 0 ? 'text-purple-700 font-semibold' : 'text-gray-400' }}">
                        {{ number_format($s->overpay_balance, 0, ',', '.') }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if($s->is_active)
                            <span class="bg-green-100 text-green-700 px-2 py-0.5 rounded text-xs">Aktif</span>
                        @else
                            <span class="bg-gray-200 text-gray-600 px-2 py-0.5 rounded text-xs">Nonaktif</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <a href="{{ route('purchasing.suppliers.show', $s->id) }}" class="text-gray-600">Rincian</a>
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            @if(!$s->is_active)
                                <form method="POST" action="{{ route('purchasing.suppliers.restore', $s->id) }}" class="inline">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Restore</button>
                                </form>
                            @elseif($s->is_used)
                                <form method="POST" action="{{ route('purchasing.suppliers.archive', $s->id) }}" class="inline"
                                      onsubmit="return confirm('Arsipkan pemasok ini? (nonaktif, tetap tersimpan)')">
                                    @csrf
                                    <button class="bg-yellow-500 text-white px-2 py-1 rounded text-xs" title="Sudah dipakai transaksi — hanya bisa diarsipkan">Archive</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('purchasing.suppliers.destroy', $s->id) }}" class="inline"
                                      onsubmit="return confirm('Hapus pemasok ini secara permanen? Tidak bisa dibatalkan.')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-3 py-6 text-center text-gray-400">Belum ada pemasok.</td></tr>
            @endforelse
        </tbody>
        @if($suppliers->count())
            <tfoot class="bg-gray-50 border-t font-bold text-gray-700">
                <tr>
                    <td colspan="5" class="px-3 py-2 text-right uppercase tracking-widest text-[10px]">Total</td>
                    <td class="px-3 py-2 text-right text-red-700">{{ number_format($totalAp, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-blue-700">{{ number_format($totalDp, 0, ',', '.') }}</td>
                    <td class="px-3 py-2 text-right text-purple-800">{{ number_format($totalOverpay, 0, ',', '.') }}</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

<div class="mt-3">{{ $suppliers->links() }}</div>

@include('erp.purchasing._partials.list-scripts')
@endsection
