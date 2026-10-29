<?php

namespace App\Modules\POS\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jubelio\Models\JubelioOrderLink;
use App\Modules\Marketplace\Jubelio\Services\JubelioOrderSyncService;
use App\Modules\Marketplace\Shopee\Services\ShopeePembatalanService;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Tombol "Setujui batal & Void" di tab Pembatalan.
 *
 * Shopee: status dicek langsung ke Shopee; IN_CANCEL disetujui lewat API, CANCELLED cukup
 * diteruskan. Selain itu (status lain / Shopee menolak) ERP TIDAK di-void — batal di ERP
 * tanpa batal di marketplace berarti pesanan tetap jalan di sana tapi hilang di sini.
 * Marketplace lain belum punya API: admin menyetujui di Seller Center (dikonfirmasi lewat
 * dialog tombol), ERP langsung void.
 *
 * Void-nya lewat JubelioOrderSyncService::batalkanDariErp — jalur yang sama dengan
 * pembatalan dari Jubelio, jadi DP ikut di-void (tombol void SO biasa menolak bila ada DP).
 */
class SetujuiBatalController extends Controller
{
    public function __invoke(int $so, JubelioOrderSyncService $sync, ShopeePembatalanService $shopee)
    {
        $order = SalesOrder::findOrFail($so);
        $link  = JubelioOrderLink::where('sales_order_id', $order->id)->first();
        if (!$link) {
            return back()->with('error', 'SO ini bukan pesanan marketplace — batalkan dari halaman SO.');
        }

        $catatan = '';
        if ($sn = self::orderSnShopee($order)) {
            if (!$shopee->siap()) {
                return back()->with('error', 'Toko Shopee belum terhubung (Pengaturan › Integrasi › Shopee) — setujui di Seller Centre lalu klik Tarik Permintaan Batal.');
            }
            $hasil = $shopee->setujui($sn);
            if (!$hasil['success']) {
                return back()->with('error', $hasil['message']);
            }
            $catatan = $hasil['message'] . ' ';
        }

        $hasil = $sync->batalkanDariErp($link);

        return back()->with($hasil['success'] ? 'success' : 'error', $catatan . $hasil['message']);
    }

    /** Nomor pesanan Shopee dari SO (`SP-<order_sn>`); null untuk marketplace lain. */
    public static function orderSnShopee(SalesOrder $so): ?string
    {
        $po = (string) $so->customer_po_number;

        return str_starts_with($po, 'SP-') ? substr($po, 3) : null;
    }
}
