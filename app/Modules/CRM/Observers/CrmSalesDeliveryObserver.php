<?php

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\Sales\Models\SalesDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Pemicu notifikasi "pesanan dikirim".
 *
 * Yang diamati adalah TERISINYA nomor resi, bukan status surat jalan: resi bisa
 * datang dari booking Jubelio, dari sinkron status berkala, atau diketik tangan,
 * dan hanya sesudah ada resi pelanggan punya sesuatu untuk dilacak.
 *
 * Satu surat jalan = satu notifikasi (kunci `sj:<id>:dikirim`), jadi pengiriman
 * sebagian yang menghasilkan beberapa SJ tetap mengabari tiap paketnya —
 * memang benar, karena tiap paket punya resi sendiri.
 */
class CrmSalesDeliveryObserver
{
    public function __construct(private OrderNotificationService $notifikasi)
    {
    }

    public function created(SalesDelivery $sj): void
    {
        $this->antre($sj);
    }

    public function updated(SalesDelivery $sj): void
    {
        if (! $sj->wasChanged('tracking_number')) {
            return;
        }

        $this->antre($sj);
    }

    private function antre(SalesDelivery $sj): void
    {
        try {
            if (blank($sj->tracking_number) || ! $sj->sales_order_id) {
                return;
            }

            $so = $sj->order;

            if ($so) {
                $this->notifikasi->antrekanDikirim($so, $sj);
            }
        } catch (\Throwable $e) {
            // Kegagalan mengantre kabar TIDAK boleh menggagalkan penyimpanan
            // surat jalan — barangnya sudah benar-benar berangkat.
            Log::warning('[CRM] gagal mengantre notifikasi pengiriman', [
                'sales_delivery_id' => $sj->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
