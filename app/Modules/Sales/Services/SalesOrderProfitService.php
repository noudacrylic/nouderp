<?php

namespace App\Modules\Sales\Services;

use App\Models\SalesInvoice;
use App\Modules\Analysis\Services\ChannelPricingService;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Kartu "Laba Pesanan": penjualan − HPP barang − biaya pesanan − potongan marketplace.
 *
 * Dua sumber, disebut terang-terangan di kartunya:
 *  - AKTUAL  : ada faktur posted. Penjualan = subtotal − diskon faktur, HPP = `hpp_total`
 *              faktur (HPP FIFO dari Surat Jalan, angka yang sama dengan jurnalnya).
 *  - TAKSIRAN: belum ada faktur posted. Penjualan dari SO, HPP = HPP per unit halaman Analisa
 *              × qty — barangnya belum keluar, jadi HPP FIFO-nya memang belum ada.
 *
 * SEBELUM pajak: PPh final 0,5% berlaku untuk semua penjualan dan punya kartunya sendiri.
 * Ongkir tidak dihitung — ditagih ke pembeli dan diteruskan ke kurir.
 */
class SalesOrderProfitService
{
    public function __construct(
        private SalesOrderCostService $biaya,
        private MarketplaceFeeBreakdownService $potongan,
        private ChannelPricingService $harga,
    ) {
    }

    /**
     * @return array{sumber:string,penjualan:float,hpp:float,biaya_pesanan:float,potongan_mp:?float,
     *               laba:float,margin:?float,faktur:int,hpp_kosong:array<int,string>}
     */
    public function untukPesanan(SalesOrder $so): array
    {
        $faktur = SalesInvoice::where('sales_order_id', $so->id)->where('status', 'posted')->get();
        $hppKosong = [];

        if ($faktur->isNotEmpty()) {
            $sumber    = 'aktual';
            $penjualan = (float) $faktur->sum(fn ($i) => (float) $i->subtotal - (float) $i->global_discount_amount);
            $hpp       = (float) $faktur->sum('hpp_total');
        } else {
            $sumber    = 'taksiran';
            $penjualan = (float) $so->subtotal - (float) $so->global_discount_amount;
            $perUnit   = $this->harga->hppPerUnit();
            $hpp       = 0.0;

            $so->loadMissing('items.product');
            foreach ($so->items as $item) {
                $p = $item->product;
                // Jasa & non-stok tidak punya HPP barang — sama seperti InvoicePostingService.
                if (!$p || in_array($p->sale_type, ['service', 'non_stock'], true) || in_array($p->type ?? null, ['service', 'non_stock'], true)) {
                    continue;
                }
                $unit = $perUnit->get($p->id);
                if ($unit === null) {
                    $hppKosong[] = $p->sku;
                    continue;
                }
                $hpp += (float) $item->qty * (float) ($item->conversion_to_base ?: 1) * $unit;
            }
        }

        $biayaPesanan = (float) $this->biaya->linesForOrder($so->id)->sum('amount');

        $mp         = $this->potongan->untukPesanan($so);
        $potonganMp = $mp ? MarketplaceFeeBreakdownService::potonganNonPajak($mp) : null;

        $laba = $penjualan - $hpp - $biayaPesanan - (float) $potonganMp;

        return [
            'sumber'        => $sumber,
            'penjualan'     => round($penjualan, 2),
            'hpp'           => round($hpp, 2),
            'biaya_pesanan' => round($biayaPesanan, 2),
            'potongan_mp'   => $potonganMp === null ? null : round($potonganMp, 2),
            'potongan_final'=> (bool) ($mp['final'] ?? false),
            'laba'          => round($laba, 2),
            'margin'        => $penjualan > 0 ? round($laba / $penjualan * 100, 1) : null,
            'faktur'        => $faktur->count(),
            'hpp_kosong'    => array_values(array_unique($hppKosong)),
        ];
    }
}
