@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    {{-- PAGE HEADER --}}
    <div class="flex justify-between items-center mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium mb-1">
                <a href="{{ route('sales.returns.index') }}" class="hover:text-blue-600 transition">Retur</a>
                <span>›</span>
                <span class="text-gray-600">{{ $return->return_number }}</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">Detail Retur</h1>
            <p class="text-xs text-gray-500 mt-0.5">Dokumen retur yang sudah diposting tidak dapat diubah.</p>
        </div>
        <div class="flex items-center gap-2">
            @php
                $hasRepairItems = $return->items->where('condition', 'repair')->count() > 0;
            @endphp
            @if($return->status === 'posted' && $hasRepairItems)
                <a href="{{ route('production.orders.create', [
                        'type'               => 'repair',
                        'repair_source_type' => 'return',
                        'repair_source_id'   => $return->id,
                        'repair_ref'         => $return->return_number,
                    ]) }}"
                   class="inline-flex items-center gap-2 bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-xl text-sm font-bold transition-all"
                   title="Buat Order Produksi perbaikan dari retur ini">
                    + OP Perbaikan
                </a>
            @endif
            @if($return->canBeVoided())
                <form action="{{ route('sales.returns.void', $return->id) }}" method="POST"
                      onsubmit="return confirm('Void retur ini? Tindakan tidak dapat dibatalkan.')">
                    @csrf
                    <button type="submit"
                        class="inline-flex items-center gap-2 bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-xl text-sm font-bold transition-all">
                        Void
                    </button>
                </form>
            @endif
            <a href="{{ route('sales.returns.index') }}"
               class="inline-flex items-center gap-2 border border-gray-200 text-gray-600 hover:bg-gray-50 bg-white px-4 py-2 rounded-xl text-sm font-semibold transition-all">
                ← Kembali
            </a>
        </div>
    </div>

    {{-- FLASH MESSAGES --}}

    <div class="grid grid-cols-12 gap-5">

        {{-- LEFT COLUMN --}}
        <div class="col-span-8 space-y-4">

            {{-- ═══ HEADER INFO ═══ --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 bg-blue-600 text-white rounded-lg flex items-center justify-center text-xs font-black">1</span>
                        <h3 class="font-bold text-gray-700">Informasi Dokumen</h3>
                    </div>
                    @if($return->status === 'posted')
                        <span class="inline-flex items-center gap-1.5 bg-green-100 text-green-700 px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border border-green-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                            POSTED
                        </span>
                    @elseif($return->status === 'void')
                        <span class="inline-flex items-center gap-1.5 bg-red-100 text-red-700 px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border border-red-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                            VOID
                        </span>
                    @endif
                </div>

                <div class="grid grid-cols-3 gap-5">
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">No. Retur</div>
                        <div class="font-black text-blue-600 text-base">{{ $return->return_number }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Tanggal Retur</div>
                        <div class="font-bold text-gray-800">{{ $return->return_date->format('d/m/Y') }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Jenis Retur</div>
                        <div class="font-bold text-gray-800">
                            @if($return->invoice_id)
                                <span class="inline-flex items-center gap-1 bg-blue-100 text-blue-700 text-xs font-bold px-2.5 py-1 rounded-lg">
                                    🛍️ Dari Invoice
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 bg-indigo-100 text-indigo-700 text-xs font-bold px-2.5 py-1 rounded-lg">
                                    📋 Dari Sales Order
                                </span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Customer</div>
                        <div class="font-bold text-gray-800">{{ $return->customer->name ?? '-' }}</div>
                        @if($return->customer->is_marketplace ?? false)
                            <div class="text-[10px] text-purple-600 font-bold mt-0.5">🏪 Marketplace</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Referensi Dokumen</div>
                        @if($return->invoice)
                            <a href="{{ route('sales.invoices.show', $return->invoice_id) }}"
                               class="font-bold text-blue-600 hover:underline text-sm">
                                {{ $return->invoice->invoice_number }}
                            </a>
                            <div class="text-[10px] text-gray-400 mt-0.5">Invoice</div>
                        @elseif($return->salesOrder)
                            <a href="{{ route('sales.orders.show', $return->sales_order_id) }}"
                               class="font-bold text-blue-600 hover:underline text-sm">
                                {{ $return->salesOrder->order_number }}
                            </a>
                            <div class="text-[10px] text-gray-400 mt-0.5">Sales Order</div>
                        @else
                            <span class="text-gray-400">-</span>
                        @endif
                    </div>
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Total Retur</div>
                        <div class="font-black text-green-600 text-base">Rp {{ number_format($return->grand_total, 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>

            {{-- ═══ ITEM LIST ═══ --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center gap-2 mb-5">
                    <span class="w-7 h-7 bg-purple-600 text-white rounded-lg flex items-center justify-center text-xs font-black">2</span>
                    <h3 class="font-bold text-gray-700">Item yang Diretur</h3>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-100">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100">
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Produk</th>
                                <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-24">Qty Retur</th>
                                <th class="px-4 py-3 text-center text-[10px] font-black text-gray-400 uppercase tracking-widest w-32">Kondisi</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-32">Harga Satuan</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Nilai Retur</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($return->items as $item)
                                <tr class="
                                    @if($item->condition === 'good') bg-green-50/40
                                    @elseif($item->condition === 'repair') bg-amber-50/40
                                    @elseif($item->condition === 'damaged') bg-red-50/40
                                    @endif
                                ">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-gray-800">{{ $item->product->name ?? 'Produk #'.$item->product_id }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="font-black text-gray-700">{{ number_format($item->qty, 0) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($item->condition === 'good')
                                            <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 text-xs font-bold px-2.5 py-1 rounded-full">🟢 Utuh</span>
                                        @elseif($item->condition === 'repair')
                                            <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-700 text-xs font-bold px-2.5 py-1 rounded-full">🟡 Perbaikan</span>
                                        @elseif($item->condition === 'damaged')
                                            <span class="inline-flex items-center gap-1 bg-red-100 text-red-700 text-xs font-bold px-2.5 py-1 rounded-full">🔴 Tidak Dapat Diperbaiki</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-600 font-medium">
                                        Rp {{ number_format($item->unit_price, 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-black text-gray-700">
                                        Rp {{ number_format($item->subtotal, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-gray-300 font-medium text-sm">
                                        Tidak ada item
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 border-t border-gray-200">
                                <td colspan="4" class="px-4 py-3 text-right font-black text-gray-700 text-sm uppercase tracking-wide">Total Retur</td>
                                <td class="px-4 py-3 text-right font-black text-green-600 text-base">
                                    Rp {{ number_format($return->grand_total, 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- ═══ JOURNAL ENTRIES ═══ --}}
            @if($journal)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 bg-gray-700 text-white rounded-lg flex items-center justify-center text-xs font-black">3</span>
                        <h3 class="font-bold text-gray-700">Entri Jurnal</h3>
                    </div>
                    <span class="text-xs font-bold text-gray-400">{{ $journal->journal_number }}</span>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-100">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100">
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Akun</th>
                                <th class="px-4 py-3 text-left text-[10px] font-black text-gray-400 uppercase tracking-widest">Keterangan</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Debit</th>
                                <th class="px-4 py-3 text-right text-[10px] font-black text-gray-400 uppercase tracking-widest w-36">Kredit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($journal->lines as $line)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-gray-800 text-xs">{{ $line->account->code ?? '-' }}</div>
                                        <div class="text-gray-500 text-[11px]">{{ $line->account->name ?? '-' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 text-xs">{{ $line->description ?? '-' }}</td>
                                    <td class="px-4 py-3 text-right font-semibold {{ $line->debit > 0 ? 'text-gray-800' : 'text-gray-300' }}">
                                        @if($line->debit > 0)
                                            Rp {{ number_format($line->debit, 0, ',', '.') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold {{ $line->credit > 0 ? 'text-gray-800' : 'text-gray-300' }}">
                                        @if($line->credit > 0)
                                            Rp {{ number_format($line->credit, 0, ',', '.') }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 border-t-2 border-gray-200">
                                <td colspan="2" class="px-4 py-3 font-black text-gray-700 text-xs uppercase tracking-wide">Total</td>
                                <td class="px-4 py-3 text-right font-black text-gray-800">
                                    Rp {{ number_format($journal->lines->sum('debit'), 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-3 text-right font-black text-gray-800">
                                    Rp {{ number_format($journal->lines->sum('credit'), 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-3 flex items-center justify-between text-xs text-gray-400">
                    <span>Posted: {{ $journal->posted_at ? \Carbon\Carbon::parse($journal->posted_at)->format('d/m/Y H:i') : '-' }}</span>
                    <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold text-[10px]">
                        ✓ BALANCED
                    </span>
                </div>
            </div>
            @endif

        </div>

        {{-- RIGHT COLUMN --}}
        <div class="col-span-4 space-y-4">

            {{-- SUMMARY CARD --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="bg-gradient-to-br from-blue-600 to-indigo-600 p-5 text-white">
                    <div class="text-xs font-bold uppercase tracking-widest opacity-75 mb-1">Total Retur</div>
                    <div class="text-3xl font-black">Rp {{ number_format($return->grand_total, 0, ',', '.') }}</div>
                    <div class="text-xs opacity-75 mt-1">{{ $return->return_date->format('d F Y') }}</div>
                </div>
                <div class="p-5 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">No. Retur</span>
                        <span class="font-bold text-gray-800">{{ $return->return_number }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Customer</span>
                        <span class="font-bold text-gray-800">{{ $return->customer->name ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Jenis</span>
                        <span class="font-bold text-gray-800">{{ $return->invoice_id ? 'Retur Invoice' : 'Retur SO' }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-500">Jumlah Item</span>
                        <span class="font-bold text-gray-800">{{ $return->items->count() }} produk</span>
                    </div>
                    <div class="border-t border-gray-100 pt-3 flex justify-between text-sm">
                        <span class="text-gray-500">Status</span>
                        @if($return->status === 'posted')
                            <span class="font-black text-green-600">POSTED</span>
                        @elseif($return->status === 'draft')
                            <span class="font-black text-amber-600">DRAFT</span>
                        @elseif($return->status === 'void')
                            <span class="font-black text-red-600">VOID</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- EFEK ACCOUNTING --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span class="w-6 h-6 bg-orange-500 text-white rounded-lg flex items-center justify-center text-[10px] font-black">!</span>
                    <h3 class="font-bold text-gray-700 text-sm">Dampak Akuntansi</h3>
                </div>
                <ul class="space-y-2.5 text-xs text-gray-600 font-medium">
                    @if($return->invoice_id)
                        <li class="flex items-start gap-2">
                            <span class="text-blue-500 font-black mt-0.5">•</span>
                            <span>Revenue (4001) dibalik sebesar <strong>Rp {{ number_format($return->grand_total, 0, ',', '.') }}</strong></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="text-blue-500 font-black mt-0.5">•</span>
                            <span>HPP (5001) di-reverse sesuai nilai COGS per kondisi barang</span>
                        </li>
                    @else
                        <li class="flex items-start gap-2">
                            <span class="text-indigo-500 font-black mt-0.5">•</span>
                            <span>Uang Muka (2105) dibalik sebesar <strong>Rp {{ number_format($return->grand_total, 0, ',', '.') }}</strong></span>
                        </li>
                    @endif
                    <li class="flex items-start gap-2">
                        <span class="text-green-500 font-black mt-0.5">•</span>
                        <span>Saldo Kelebihan Bayar customer bertambah</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-purple-500 font-black mt-0.5">•</span>
                        <span>Barang utuh masuk persediaan, barang perbaikan masuk persediaan perbaikan, barang rusak masuk beban kerugian retur.</span>
                    </li>
                </ul>
            </div>

            {{-- KONDISI RINGKASAN --}}
            @php
                $conditionGroups = $return->items->groupBy('condition');
            @endphp
            @if($conditionGroups->count() > 0)
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h3 class="font-bold text-gray-700 text-sm mb-3">Ringkasan Kondisi Barang</h3>
                <div class="space-y-2">
                    @if($conditionGroups->has('good'))
                        <div class="flex justify-between items-center bg-green-50 rounded-lg px-3 py-2">
                            <span class="text-xs font-bold text-green-700">🟢 Utuh (Kembali ke Stok)</span>
                            <span class="text-xs font-black text-green-700">{{ $conditionGroups['good']->sum('qty') }} pcs</span>
                        </div>
                    @endif
                    @if($conditionGroups->has('repair'))
                        <div class="flex justify-between items-center bg-amber-50 rounded-lg px-3 py-2">
                            <span class="text-xs font-bold text-amber-700">🟡 Perbaikan (Stok Rusak)</span>
                            <span class="text-xs font-black text-amber-700">{{ $conditionGroups['repair']->sum('qty') }} pcs</span>
                        </div>
                    @endif
                    @if($conditionGroups->has('damaged'))
                        <div class="flex justify-between items-center bg-red-50 rounded-lg px-3 py-2">
                            <span class="text-xs font-bold text-red-700">🔴 Tidak Dapat Diperbaiki (Beban Kerugian)</span>
                            <span class="text-xs font-black text-red-700">{{ $conditionGroups['damaged']->sum('qty') }} pcs</span>
                        </div>
                    @endif
                </div>
            </div>
            @endif

        </div>
    </div>

    @include('erp.sales._partials.relations-return')
</div>
@endsection
