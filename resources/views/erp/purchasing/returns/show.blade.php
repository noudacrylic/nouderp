@extends('layouts.erp')

@section('content')
<div class="w-full px-6 py-4">

    <div class="flex justify-between items-center mb-6">
        <div>
            <div class="flex items-center gap-2 text-xs text-gray-400 font-medium mb-1">
                <a href="{{ route('purchasing.returns.index') }}" class="hover:text-blue-600 transition">Retur Pembelian</a>
                <span>›</span>
                <span class="text-gray-600">{{ $return->return_number }}</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800 flex items-center gap-3">
                {{ $return->return_number }}
                @if($return->status === 'posted')
                    <span class="bg-green-100 text-green-700 text-[10px] font-black px-2 py-1 rounded-full uppercase border border-green-200">POSTED</span>
                @elseif($return->status === 'draft')
                    <span class="bg-amber-100 text-amber-700 text-[10px] font-black px-2 py-1 rounded-full uppercase border border-amber-200">DRAF</span>
                @else
                    <span class="bg-gray-100 text-gray-500 text-[10px] font-black px-2 py-1 rounded-full uppercase border border-gray-200">{{ strtoupper($return->status) }}</span>
                @endif
                @if($return->return_type === 'po')
                    <span class="bg-purple-100 text-purple-700 text-[10px] font-black px-2 py-1 rounded-full uppercase">Refund DP</span>
                @else
                    <span class="bg-blue-100 text-blue-700 text-[10px] font-black px-2 py-1 rounded-full uppercase">Faktur</span>
                @endif
            </h1>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            @if($return->status === 'draft')
                <a href="{{ route('purchasing.returns.edit', $return->id) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition">Edit</a>
                <form method="POST" action="{{ route('purchasing.returns.post', $return->id) }}" onsubmit="return confirm('POST retur ini? Jurnal & saldo akan diperbarui.')">
                    @csrf
                    <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition">POST</button>
                </form>
                <form method="POST" action="{{ route('purchasing.returns.destroy', $return->id) }}" onsubmit="return confirm('Hapus draf retur ini?')">
                    @csrf @method('DELETE')
                    <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-xl text-sm font-bold transition">Hapus</button>
                </form>
            @elseif($return->status === 'posted')
                @if($return->canBeVoided())
                    <form method="POST" action="{{ route('purchasing.returns.void', $return->id) }}" onsubmit="return confirm('VOID retur ini? Jurnal di-void & saldo dikembalikan.')">
                        @csrf
                        <button class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-xl text-sm font-bold transition">Void</button>
                    </form>
                @endif
            @endif
            <a href="{{ route('purchasing.returns.index') }}" class="border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-xl text-sm font-semibold transition">← Kembali</a>
        </div>
    </div>


    <div class="grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-8 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-700 mb-3">Detail</h3>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div><span class="text-gray-500">Pemasok</span><div class="font-bold text-gray-800">{{ $return->supplier->name ?? '-' }}</div></div>
                <div><span class="text-gray-500">Tanggal Retur</span><div class="font-bold text-gray-800">{{ $return->return_date->format('d M Y') }}</div></div>
                <div><span class="text-gray-500">Referensi</span>
                    <div class="font-bold text-gray-800">
                        {{ $return->return_type === 'po'
                            ? ($return->purchaseOrder->po_number ?? '-')
                            : ($return->purchaseInvoice->invoice_number ?? '-') }}
                    </div>
                </div>
                <div><span class="text-gray-500">Tipe Retur</span><div class="font-bold text-gray-800">{{ $return->return_type === 'po' ? 'Refund DP (PO)' : 'Retur Barang (Faktur)' }}</div></div>
                @if($return->warehouse_id)
                    <div><span class="text-gray-500">Gudang</span><div class="font-bold text-gray-800">{{ $return->warehouse->name ?? '-' }}</div></div>
                @endif
            </div>
            @if($return->notes)
                <div class="mt-4 pt-3 border-t border-gray-100">
                    <div class="text-[10px] text-gray-500 uppercase tracking-widest font-bold mb-1">Catatan</div>
                    <div class="whitespace-pre-line text-gray-700 text-sm">{{ $return->notes }}</div>
                </div>
            @endif
        </div>
        <div class="col-span-4 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <h3 class="font-bold text-gray-700 mb-3">Ringkasan</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span class="font-bold">Rp {{ number_format($return->subtotal, 0, ',', '.') }}</span></div>
                @if((float) $return->ppn_amount > 0)
                    <div class="flex justify-between"><span class="text-gray-500">PPN</span><span>Rp {{ number_format($return->ppn_amount, 0, ',', '.') }}</span></div>
                @endif
                <div class="border-t border-gray-100 pt-2 flex justify-between">
                    <span class="font-black text-gray-700">Total Refund</span>
                    <span class="font-black text-purple-600 text-lg">Rp {{ number_format($return->total, 0, ',', '.') }}</span>
                </div>
            </div>
        </div>
    </div>

    @if($return->return_type === 'invoice' && $return->items->count())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-700">Item Diretur</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-5 py-3 text-left font-black text-[10px] uppercase tracking-widest">Produk</th>
                        <th class="px-5 py-3 text-right font-black text-[10px] uppercase tracking-widest w-24">Qty</th>
                        <th class="px-5 py-3 text-right font-black text-[10px] uppercase tracking-widest w-32">Refund/Pcs</th>
                        <th class="px-5 py-3 text-right font-black text-[10px] uppercase tracking-widest w-32">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($return->items as $item)
                        <tr>
                            <td class="px-5 py-3 font-medium">{{ $item->product->name ?? '-' }}</td>
                            <td class="px-5 py-3 text-right">{{ rtrim(rtrim(number_format($item->qty, 4), '0'), '.') }}</td>
                            <td class="px-5 py-3 text-right">{{ number_format($item->unit_cost, 0, ',', '.') }}</td>
                            <td class="px-5 py-3 text-right font-bold">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($return->return_type === 'po' && !empty($return->dp_allocation))
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-4">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-700">DP Allocation (FIFO)</h3>
                <p class="text-[10px] text-gray-500 italic mt-0.5">Saldo Uang Muka yang dipindah ke Piutang Lebih Bayar.</p>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-5 py-3 text-left font-black text-[10px] uppercase tracking-widest">Ref Pembayaran</th>
                        <th class="px-5 py-3 text-right font-black text-[10px] uppercase tracking-widest w-32">Nominal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($return->dp_allocation as $alloc)
                        @php
                            $pay = \App\Modules\Purchasing\Models\SupplierPayment::find($alloc['payment_id'] ?? null);
                        @endphp
                        <tr>
                            <td class="px-5 py-3 font-medium">{{ $pay->payment_number ?? ('Pembayaran #'.($alloc['payment_id'] ?? '?')) }}</td>
                            <td class="px-5 py-3 text-right font-bold">{{ number_format($alloc['amount'] ?? 0, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($journal)
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-700">Jurnal — {{ $journal->journal_number }}</h3>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-5 py-3 text-left font-black text-[10px] uppercase tracking-widest">Akun</th>
                        <th class="px-5 py-3 text-left font-black text-[10px] uppercase tracking-widest">Keterangan</th>
                        <th class="px-5 py-3 text-right font-black text-[10px] uppercase tracking-widest w-28">Debit</th>
                        <th class="px-5 py-3 text-right font-black text-[10px] uppercase tracking-widest w-28">Kredit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($journal->lines as $line)
                        <tr>
                            <td class="px-5 py-3">{{ $line->account->code ?? '?' }} — {{ $line->account->name ?? '-' }}</td>
                            <td class="px-5 py-3 text-gray-500 italic">{{ $line->description }}</td>
                            <td class="px-5 py-3 text-right font-mono">{{ $line->debit > 0 ? number_format($line->debit, 0, ',', '.') : '' }}</td>
                            <td class="px-5 py-3 text-right font-mono">{{ $line->credit > 0 ? number_format($line->credit, 0, ',', '.') : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('erp.purchasing._partials.relations-return', ['return' => $return])

</div>
@endsection
