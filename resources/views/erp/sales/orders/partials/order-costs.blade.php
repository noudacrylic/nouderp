@php
    // Kartu Biaya Pesanan: pengeluaran pihak luar (tukang pasang, jasa antar, sewa alat)
    // yang ikut jadi HPP pesanan ini. Sumbernya baris Pengeluaran Umum bertaut SO —
    // lihat SalesOrderCostService.
    $costLines = app(\App\Modules\Sales\Services\SalesOrderCostService::class)->linesForOrder($so->id);
    $costTotal = (float) $costLines->sum('amount');
    // Laba pesanan (termasuk biaya ini) ada di kartu tersendiri — partials/order-profit.
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
@endphp

@if($so->status === 'confirmed' || $costLines->isNotEmpty())
<div class="mt-4">
    <div class="bg-white border border-gray-100 rounded-lg shadow-sm p-4">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Biaya Pesanan</div>
                <div class="text-xs text-gray-500 mt-0.5">
                    Tukang pasang, jasa antar, sewa alat dari pihak luar — ikut jadi HPP pesanan ini saat difakturkan.
                </div>
            </div>
            @if($so->status === 'confirmed')
                <a href="{{ route('finance.cash-bank.disbursements.create', ['type' => 'general', 'sales_order_id' => $so->id]) }}"
                   class="border border-indigo-300 text-indigo-700 hover:bg-indigo-50 px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">
                    + Catat Biaya
                </a>
            @endif
        </div>

        @if($costLines->isEmpty())
            <div class="text-sm text-gray-400 italic mt-3">Belum ada biaya pesanan.</div>
        @else
            <div class="overflow-x-auto mt-3">
                <table class="w-full text-sm">
                    <thead class="text-[11px] uppercase text-gray-400 border-b">
                        <tr>
                            <th class="py-1.5 pr-3 text-left">Tanggal</th>
                            <th class="py-1.5 pr-3 text-left">Pengeluaran</th>
                            <th class="py-1.5 pr-3 text-left">Keterangan</th>
                            <th class="py-1.5 pr-3 text-left">Akun</th>
                            <th class="py-1.5 pr-3 text-left">Status</th>
                            <th class="py-1.5 text-right">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($costLines as $l)
                            @php $cd = $l->disbursement; @endphp
                            <tr class="border-b border-gray-50">
                                <td class="py-1.5 pr-3 whitespace-nowrap">{{ optional($cd->date)->format('d M Y') }}</td>
                                <td class="py-1.5 pr-3 whitespace-nowrap">
                                    <a href="{{ route('finance.cash-bank.disbursements.show', $cd->id) }}" class="text-blue-600 hover:underline">{{ $cd->number }}</a>
                                </td>
                                <td class="py-1.5 pr-3">{{ $l->description ?: '—' }}</td>
                                <td class="py-1.5 pr-3 text-xs text-gray-600">{{ $l->account->code ?? '' }} {{ $l->account->name ?? '' }}</td>
                                <td class="py-1.5 pr-3 whitespace-nowrap">
                                    @if($cd->isDraft())
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-gray-100 text-gray-600">Draf</span>
                                    @elseif($l->costInvoice)
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-green-100 text-green-700">Diakui · {{ $l->costInvoice->invoice_number }}</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700">Menunggu faktur</span>
                                    @endif
                                </td>
                                <td class="py-1.5 text-right whitespace-nowrap">{{ $rp($l->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td colspan="5" class="pt-2 pr-3 text-right">Total biaya pesanan</td>
                            <td class="pt-2 text-right whitespace-nowrap">{{ $rp($costTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
@endif
