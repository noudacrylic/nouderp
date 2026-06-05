@extends('layouts.erp')

@section('content')

    <div class="p-6">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Ledger Gudang</h2>
                <p class="text-gray-600 font-medium">Gudang: {{ $warehouse->name }}</p>
            </div>
            <a href="{{ route('inventory.warehouses.index') }}"
                class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded transition font-semibold">
                &larr; Kembali ke Gudang
            </a>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full border-collapse">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Tanggal</th>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Produk</th>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Referensi</th>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Tipe</th>
                        <th class="p-4 text-right font-bold text-gray-600 uppercase text-xs">Qty Masuk</th>
                        <th class="p-4 text-right font-bold text-gray-600 uppercase text-xs">Qty Keluar</th>
                        <th class="p-4 text-right font-bold text-gray-600 uppercase text-xs">Saldo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($ledger as $row)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="p-4 text-sm text-gray-700">
                                {{ $row->created_at->format('d M Y') }}
                            </td>
                            <td class="p-4 text-sm font-medium text-gray-800">
                                <div class="font-bold">{{ $row->product->name ?? '-' }}</div>
                                <div class="text-[10px] font-mono text-gray-400">{{ $row->product->sku ?? '' }}</div>
                            </td>
                            <td class="p-4 text-sm font-bold text-blue-600">
                                #{{ $row->transaction_id }}
                            </td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase
                                                                     {{ $row->transaction_type == 'opening' ? 'bg-green-100 text-green-700' : '' }}
                                                                     {{ $row->transaction_type == 'sale' ? 'bg-blue-100 text-blue-700' : '' }}
                                                                     {{ $row->transaction_type == 'purchase' ? 'bg-yellow-100 text-yellow-700' : '' }}
                                                                     {{ $row->transaction_type == 'adjustment_in' || $row->transaction_type == 'adjustment_out' ? 'bg-purple-100 text-purple-700' : '' }}
                                                                     {{ $row->transaction_type == 'transfer_in' || $row->transaction_type == 'transfer_out' ? 'bg-orange-100 text-orange-700' : '' }}
                                                                 ">
                                    {{ str_replace('_', ' ', $row->transaction_type) }}
                                </span>
                            </td>
                            <td class="p-4 text-right text-sm font-semibold text-green-600">
                                @if ($row->qty_in > 0)
                                    +{{ number_format($row->qty_in, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="p-4 text-right text-sm font-semibold text-red-600">
                                @if ($row->qty_out > 0)
                                    -{{ number_format($row->qty_out, 2) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="p-4 text-right text-sm font-bold text-gray-900 bg-gray-50/50">
                                {{ number_format($row->balance, 2) }}
                            </td>
                        </tr>
                    @endforeach

                    @if ($ledger->isEmpty())
                        <tr>
                            <td colspan="7" class="p-12 text-center text-gray-400 italic">
                                Tidak ada catatan untuk gudang ini.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@endsection