@php
    // Subtotal kini adalah NET (sudah dikurangi diskon per item)
    $subtotal = $so->items->sum('line_total');
    $discountItem = 0; // Tidak ditampilkan di summary agar tidak redundan
    $dpp = $subtotal - $so->global_discount_amount;
    $advancePaid = $advancePaid ?? 0;
    $remaining = $so->grand_total - $advancePaid;
@endphp

@include('erp.components.transaction-summary', [
    'notes' => $so->notes ?? '-',
    'updateNotesUrl' => route('sales.orders.update-notes', $so->id),
    'subtotal' => $subtotal,
    'discountItem' => $discountItem,
    'discountGlobal' => $so->global_discount_amount,
    'dpp' => $dpp,
    'ppn' => $so->ppn_amount,
    'shipping' => $so->shipping_cost,
    'shippingGross' => $so->shipping_gross,
    'shippingDiscount' => max(0, (float) ($so->shipping_gross ?? 0) - (float) ($so->shipping_cost ?? 0)),
    'additionalFee' => $so->additional_fee,
    'marketplaceFee' => $so->marketplace_fee,
    'uniqueCode' => (int) ($so->unique_code ?? 0),
    'grandTotal' => $so->grand_total,
    'advancePaid' => $advancePaid,
    'remaining' => ($invoiceStatus === 'invoiced') ? null : $remaining
])

{{-- Kesepakatan batas minimal DP — inilah yang dipakai tautan pembayaran DP. --}}
@if($so->status !== 'void' && $remaining > 0)
    @php
        $mdBase = $so->minDpBaseAmount();
        $mdSisa = $so->minDpAmount();
    @endphp
    <div class="mt-4 p-3 rounded-lg border text-sm flex items-start justify-between gap-3
        {{ $so->hasCustomMinDp() ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-200' }}">
        <div>
            <div class="font-bold {{ $so->hasCustomMinDp() ? 'text-amber-800' : 'text-gray-700' }}">
                Minimal DP
                @if($so->hasCustomMinDp())
                    <span class="ml-1 px-1.5 py-0.5 rounded bg-amber-200 text-amber-900 text-[10px] font-bold uppercase tracking-wide">Kesepakatan</span>
                @endif
            </div>
            <div class="text-[11px] text-gray-500 mt-0.5">
                {{ rtrim(rtrim(number_format($so->minDpPercent(), 2, ',', '.'), '0'), ',') }}% dari total
                @if($advancePaid > 0)
                    &mdash; sisa yang harus dibayar minimal <b>{{ format_rupiah($mdSisa) }}</b>
                @endif
                @unless($so->hasCustomMinDp())
                    (bawaan)
                @endunless
            </div>
        </div>
        <div class="text-right font-bold whitespace-nowrap {{ $so->hasCustomMinDp() ? 'text-amber-800' : 'text-gray-700' }}">
            {{ format_rupiah($mdBase) }}
        </div>
    </div>
@endif

{{-- Kesepakatan keep stock + kondisi stok saat ini. Ada di sini karena kesepakatannya
     sering lahir setelah SO dikonfirmasi (pembeli menelepon saat pembayarannya tertahan),
     dan SO yang sudah dikonfirmasi tidak bisa diedit lewat form. --}}
@if($so->status !== 'void')
    @php
        $kurangStok = $so->allow_backorder ? [] : app(\App\Modules\Sales\Services\SalesOrderStockCheck::class)->shortages($so);
    @endphp
    <div class="mt-3 p-3 rounded-lg border text-sm
        {{ $so->allow_backorder ? 'bg-amber-50 border-amber-200' : (empty($kurangStok) ? 'bg-gray-50 border-gray-200' : 'bg-red-50 border-red-200') }}">
        <form method="POST" action="{{ route('sales.orders.keep-stock', $so->id) }}" class="flex items-start gap-2">
            @csrf
            <input type="hidden" name="allow_backorder" value="{{ $so->allow_backorder ? 0 : 1 }}">
            <input type="checkbox" onchange="this.form.submit()" {{ $so->allow_backorder ? 'checked' : '' }}
                class="mt-0.5 rounded border-gray-300 text-amber-500 focus:ring-amber-400 cursor-pointer">
            <div class="flex-1">
                <div class="font-bold {{ $so->allow_backorder ? 'text-amber-800' : 'text-gray-700' }}">Keep stock (stok menyusul)</div>
                <div class="text-[11px] text-gray-500 mt-0.5">
                    @if($so->allow_backorder)
                        Disepakati dengan pembeli — pembayaran tetap diterima walau stok tidak cukup, stok boleh minus.
                    @elseif(empty($kurangStok))
                        Stok saat ini mencukupi. Centang bila pembeli setuju memesan barang yang stoknya belum ada.
                    @else
                        <span class="text-red-700 font-semibold">Pembayaran lewat tautan sedang ditolak — stok tidak cukup:</span>
                        <ul class="mt-1 space-y-0.5 text-red-700">
                            @foreach($kurangStok as $k)
                                <li>{{ $k['sku'] }} — dipesan {{ format_qty($k['needed']) }}, tersisa {{ format_qty($k['available']) }} (kurang {{ format_qty($k['short']) }})</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </form>
    </div>
@endif

@if(($invoiceStatus ?? '') === 'invoiced')
    <div class="mt-4 p-3 bg-green-50 rounded-lg border border-green-200 text-green-700 text-sm font-bold flex items-center gap-2 shadow-sm">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
        </svg>
        <span>Pesanan Selesai Di-Faktur — Pembayaran dikelola melalui modul Faktur</span>
    </div>
@endif


