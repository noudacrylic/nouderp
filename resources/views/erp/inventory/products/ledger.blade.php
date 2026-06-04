@extends('layouts.erp')

@section('content')

    <div class="p-6">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Product Ledger</h2>
                <p class="text-gray-600 font-medium">{{ $product->sku }} - {{ $product->name }}</p>
            </div>
            <a href="{{ route('inventory.products.index') }}"
                class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded transition font-semibold">
                &larr; Back to Products
            </a>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full border-collapse">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Date</th>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Warehouse</th>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Reference</th>
                        <th class="p-4 text-left font-bold text-gray-600 uppercase text-xs">Type</th>
                        <th class="p-4 text-right font-bold text-gray-600 uppercase text-xs">Qty In</th>
                        <th class="p-4 text-right font-bold text-gray-600 uppercase text-xs">Qty Out</th>
                        <th class="p-4 text-right font-bold text-gray-600 uppercase text-xs">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($ledger as $row)
                        @php
                            $type = $row->transaction_type;
                            $badge = match(true) {
                                str_starts_with($type, 'opening')          => 'bg-green-100 text-green-700',
                                str_starts_with($type, 'purchase')         => 'bg-yellow-100 text-yellow-700',
                                str_starts_with($type, 'sale')             => 'bg-blue-100 text-blue-700',
                                str_starts_with($type, 'adjustment_in')    => 'bg-purple-100 text-purple-700',
                                str_starts_with($type, 'adjustment_out')   => 'bg-orange-100 text-orange-700',
                                str_starts_with($type, 'adjustment_void')  => 'bg-gray-200 text-gray-500',
                                str_starts_with($type, 'transfer_in')      => 'bg-teal-100 text-teal-700',
                                str_starts_with($type, 'transfer_out')     => 'bg-indigo-100 text-indigo-700',
                                str_starts_with($type, 'transfer_void')    => 'bg-gray-200 text-gray-500',
                                default                                    => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 transition">
                            <td class="p-4 text-sm text-gray-700 whitespace-nowrap">
                                {{ $row->created_at->format('d M Y H:i') }}
                            </td>
                            <td class="p-4 text-sm font-medium text-gray-800">
                                {{ optional($row->warehouse)->name ?? '—' }}
                            </td>
                            <td class="p-4 text-sm font-bold text-blue-600">
                                #{{ $row->transaction_id }}
                            </td>
                            <td class="p-4">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $badge }}">
                                    {{ str_replace('_', ' ', $type) }}
                                </span>
                            </td>
                            <td class="p-4 text-right text-sm font-semibold text-green-600">
                                @if ($row->qty_in > 0)
                                    +{{ number_format($row->qty_in, 2) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="p-4 text-right text-sm font-semibold text-red-600">
                                @if ($row->qty_out > 0)
                                    −{{ number_format($row->qty_out, 2) }}
                                @else
                                    —
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
                                No records found for this product.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@endsection