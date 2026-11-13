{{--
    Kartu Potongan Marketplace — biaya admin, affiliate, pajak, dan saldo yang masuk ke Saldo
    Penjualan. Sumbernya Shopee langsung (pesanan Shopee, termasuk penyesuaian retur setelah dana
    dilepas), Jubelio (final setelah pesanan selesai), atau estimasi dari setingan
    integrasi bila Jubelio belum/tidak membawa angka (lihat MarketplaceFeeBreakdownService).
--}}
@php
    $mpFee = $so->status !== 'void' ? app(\App\Modules\Sales\Services\MarketplaceFeeBreakdownService::class)->untukPesanan($so) : null;
@endphp

@if($mpFee)
@php
    $mpCfg = \App\Models\MarketplaceConfig::with('walletAccount')->where('customer_id', $so->customer_id)->where('is_active', true)->first();
    $mpLink = \App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink::where('sales_order_id', $so->id)->first();
    $mpShopee = \App\Modules\Marketplace\Shopee\Services\ShopeeRincianDanaService::orderSn($so) !== null
        && app(\App\Modules\Marketplace\Shopee\Services\ShopeeRincianDanaService::class)->toko() !== null;
    $mpSumberLabel = ['laporan' => 'laporan penghasilan (Rekonsiliasi)', 'shopee' => 'Shopee'][$mpFee['sumber']] ?? 'Jubelio';
    $mpPenyesuaian = $mpFee['penyesuaian'] ?? [];
    $rpNeg = fn ($n) => '− ' . number_format(abs((float) $n), 0, ',', '.');
    $rpPos = fn ($n) => number_format((float) $n, 0, ',', '.');
    [$mpBadge, $mpBadgeCls] = $mpFee['final']
        ? [match ($mpFee['sumber']) { 'laporan' => 'Final · dari laporan penghasilan', 'shopee' => 'Final · dari Shopee', default => 'Final · dari Jubelio' }, 'bg-green-100 text-green-700']
        : (in_array($mpFee['sumber'], ['jubelio', 'shopee'], true)
            ? ['Estimasi · dari ' . ($mpFee['sumber'] === 'shopee' ? 'Shopee' : 'Jubelio'), 'bg-amber-100 text-amber-800']
            : ['Estimasi · dari setingan integrasi', 'bg-gray-100 text-gray-600']);

    // Potongan yang benar-benar dibukukan saat pencairan (bila sudah cair) — supaya terlihat
    // kalau jurnalnya masih memakai estimasi lama.
    $mpCairInv = \App\Models\SalesInvoice::where('sales_order_id', $so->id)->where('status', '!=', 'void')
        ->where('fee_at_settlement', true)->where('marketplace_processed', true)->latest('id')->first();
    $mpPotongan = $mpFee['admin'] + $mpFee['affiliate'] + $mpFee['pajak'] + $mpFee['lain'];
    $mpSelisih = $mpCairInv ? round((float) $mpCairInv->marketplace_fee - $mpPotongan, 0) : 0;
@endphp
{{-- $tersemat: dipasang DI DALAM kartu "Marketplace — perjalanan dana" di halaman faktur
     (tanpa bingkai kartu sendiri) supaya faktur tidak punya dua kartu marketplace. --}}
@php $tersemat = $tersemat ?? false; @endphp
<div class="{{ $tersemat ? 'mt-4 pt-4 border-t border-gray-100' : 'mt-4' }}">
    <div class="{{ $tersemat ? '' : 'bg-white border border-gray-100 rounded-lg shadow-sm p-4' }} text-sm">
        <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
            <div>
                <div class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">{{ $tersemat ? 'Rincian potongan' : 'Potongan Marketplace' }}</div>
                <div class="text-xs text-gray-500 mt-0.5">
                    @if($mpFee['final'] && $mpFee['sumber'] === 'shopee')
                        Angka asli dari Shopee — sama dengan rincian pesanan di Seller Centre.
                    @elseif($mpFee['final'] && $mpFee['sumber'] === 'laporan')
                        Angka asli dari laporan penghasilan marketplace yang diunggah di Rekonsiliasi.
                    @elseif($mpFee['final'])
                        Angka asli dari marketplace setelah pesanan selesai.
                    @elseif($mpFee['sumber'] === 'jubelio')
                        Perkiraan marketplace selama pesanan belum selesai — angka final diambil saat pesanan selesai.
                    @else
                        Jubelio belum membawa angka potongan; dihitung dari setingan integrasi. Angka final tetap mengikuti Jubelio.
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 rounded text-[11px] font-semibold {{ $mpBadgeCls }}">{{ $mpBadge }}</span>
                @if($mpShopee || $mpLink)
                    @php $mpDari = $mpShopee ? 'Shopee' : 'Jubelio'; @endphp
                    <form method="POST" action="{{ route('sales.orders.biaya-marketplace', $so->id) }}">
                        @csrf
                        <button class="border border-gray-300 text-gray-600 hover:bg-gray-50 px-2 py-0.5 rounded text-[11px]" title="Ambil ulang rincian potongan dari {{ $mpDari }}">↻ Ambil dari {{ $mpDari }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="max-w-md ml-auto">
            <div class="flex justify-between py-1 text-gray-600">
                <span>Nilai pesanan</span><span class="tabular-nums">{{ $rpPos($mpFee['kotor']) }}</span>
            </div>

            <div class="flex justify-between py-1 text-gray-700">
                <span>Biaya admin</span><span class="tabular-nums text-red-500">{{ $rpNeg($mpFee['admin']) }}</span>
            </div>
            @if(count($mpFee['admin_rincian']) > 1)
                @foreach($mpFee['admin_rincian'] as $label => $nilai)
                    <div class="flex justify-between pl-4 text-[11px] text-gray-400">
                        <span>{{ $label }}</span><span class="tabular-nums">{{ $rpNeg($nilai) }}</span>
                    </div>
                @endforeach
            @endif

            @if($mpFee['affiliate'] > 0)
                <div class="flex justify-between py-1 text-gray-700">
                    <span>Biaya affiliate</span><span class="tabular-nums text-red-500">{{ $rpNeg($mpFee['affiliate']) }}</span>
                </div>
            @endif

            <div class="flex justify-between py-1 text-gray-700">
                <span>Pajak <span class="text-[11px] text-gray-400">(PPh 0,5%)</span></span>
                <span class="tabular-nums {{ $mpFee['pajak'] > 0 ? 'text-red-500' : 'text-gray-400' }}">
                    {{ $mpFee['pajak'] > 0 ? $rpNeg($mpFee['pajak']) : 'tidak dipotong' }}
                </span>
            </div>

            @if(abs($mpFee['lain']) >= 1)
                <div class="flex justify-between py-1 text-gray-700">
                    <span>Lain-lain <span class="text-[11px] text-gray-400">({{ $mpFee['final'] ? 'voucher toko, ongkir, penyesuaian' : 'premi/affiliate yang belum dirinci' }})</span></span>
                    <span class="tabular-nums {{ $mpFee['lain'] > 0 ? 'text-red-500' : 'text-gray-700' }}">{{ $mpFee['lain'] > 0 ? $rpNeg($mpFee['lain']) : '+ ' . $rpPos(-$mpFee['lain']) }}</span>
                </div>
            @endif

            <div class="flex justify-between pt-2 mt-1 border-t font-bold text-gray-900">
                <span>
                    {{ $mpFee['final'] ? 'Saldo masuk' : 'Perkiraan saldo masuk' }}
                    <span class="block text-[11px] font-normal text-gray-500">ke {{ $mpCfg?->walletAccount ? $mpCfg->walletAccount->code . ' · ' . $mpCfg->walletAccount->name : 'Saldo Penjualan marketplace' }}</span>
                </span>
                <span class="tabular-nums text-emerald-700">{{ $rpPos($mpFee['saldo_masuk']) }}</span>
            </div>

            {{-- Penyesuaian SETELAH dana dilepas (retur/refund setelah cair, kompensasi) — seperti
                 "Penyesuaian Pesanan" di Seller Centre; saldo akhir = "Penghasilan Akhir". --}}
            @if(count($mpPenyesuaian))
                @foreach($mpPenyesuaian as $adj)
                    <div class="flex justify-between py-1 text-gray-700">
                        <span>{{ $adj['alasan'] }}
                            @if(!empty($adj['tanggal']))<span class="text-[11px] text-gray-400">({{ \Carbon\Carbon::parse($adj['tanggal'])->format('d/m/Y') }})</span>@endif
                        </span>
                        <span class="tabular-nums {{ $adj['nilai'] < 0 ? 'text-red-500' : 'text-emerald-700' }}">{{ $adj['nilai'] < 0 ? $rpNeg($adj['nilai']) : '+ ' . $rpPos($adj['nilai']) }}</span>
                    </div>
                @endforeach
                <div class="flex justify-between pt-2 mt-1 border-t font-bold text-gray-900">
                    <span>Saldo akhir <span class="block text-[11px] font-normal text-gray-500">setelah penyesuaian — = Penghasilan Akhir Seller Centre</span></span>
                    <span class="tabular-nums text-emerald-700">{{ $rpPos($mpFee['saldo_akhir'] ?? ($mpFee['saldo_masuk'] + array_sum(array_column($mpPenyesuaian, 'nilai')))) }}</span>
                </div>
            @endif

            @if($mpFee['diambil_at'])
                <div class="text-[10px] text-gray-400 text-right mt-1">diambil dari {{ $mpSumberLabel }} {{ $mpFee['diambil_at']->format('d/m/Y H:i') }}</div>
            @endif
            @if($mpCairInv && abs($mpSelisih) >= 1)
                <div class="mt-2 rounded bg-amber-50 border border-amber-200 px-2 py-1 text-[11px] text-amber-800">
                    Jurnal pencairan mencatat potongan {{ $rpPos($mpCairInv->marketplace_fee) }}; selisih {{ $rpPos(abs($mpSelisih)) }} dibereskan saat rekonsiliasi saldo marketplace.
                </div>
            @endif
        </div>
    </div>
</div>
@endif
