@php
    $lunas  = $remaining <= 0;
    $canPay = !$lunas && !$expired;
@endphp

<div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
    <div class="bg-emerald-600 px-6 py-5 text-white">
        <div class="text-xs uppercase tracking-widest opacity-80">Noud Acrylic</div>
        <div class="text-lg font-bold mt-1">Uang Muka (DP) — {{ $so->order_number }}</div>
    </div>

    <div class="px-6 py-5 border-b">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs text-gray-500">Untuk</div>
                <div class="text-base font-semibold text-gray-800">{{ $customer?->name ?? '-' }}</div>
            </div>
            @if($lunas)
                <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">LUNAS</span>
            @endif
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div>
                <div class="text-xs text-gray-500">Tanggal Pesanan</div>
                <div class="font-semibold">{{ \Carbon\Carbon::parse($so->order_date)->format('d M Y') }}</div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">Total Pesanan</div>
                <div class="font-semibold">Rp {{ number_format($grand_total, 0, ',', '.') }}</div>
            </div>
        </div>

        <div class="mt-3 border-t border-dashed pt-3 space-y-1 text-sm">
            <div class="flex justify-between text-gray-600">
                <span>Sudah dibayar</span>
                <span>Rp {{ number_format($paid_amount, 0, ',', '.') }}</span>
            </div>
            <div class="flex justify-between font-bold text-gray-900">
                <span>Sisa tagihan</span>
                <span class="text-emerald-700">Rp {{ number_format(max(0, $remaining), 0, ',', '.') }}</span>
            </div>
        </div>

        <a href="{{ $pdf_url }}"
           class="mt-4 flex items-center justify-center gap-2 w-full border-2 border-emerald-500 text-emerald-700 hover:bg-emerald-50 font-bold rounded-xl py-2.5 text-sm transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Download Pesanan (PDF)
        </a>
    </div>

    @if($canPay)
        @include('pay._paybox')
    @elseif($lunas)
        <div class="px-6 py-6 text-center text-sm text-emerald-700 font-semibold">Pesanan ini sudah lunas. Terima kasih!</div>
    @else
        <div class="px-6 py-6 text-center text-sm text-gray-500">Link sudah kadaluarsa. Silakan hubungi Noud Acrylic untuk link baru.</div>
    @endif

    <div class="px-6 py-4 bg-gray-50 text-center text-xs text-gray-500">
        Link berlaku hingga {{ $trx->expired_at->format('d M Y H:i') }}
    </div>
</div>
