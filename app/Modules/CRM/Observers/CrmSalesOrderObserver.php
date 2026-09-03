<?php

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Services\OrderNotificationService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\Log;

/**
 * Pemicu notifikasi dari perubahan pesanan.
 *
 * Sengaja mengamati KOLOM, bukan menempel di satu controller: pembayaran bisa
 * masuk lewat kasir, faktur, link Midtrans, atau checkout web — dan ketiganya
 * berujung pada `paid_amount` yang bertambah di baris yang sama.
 *
 * Seluruh isi observer dibungkus try/catch: kegagalan mengantre notifikasi
 * TIDAK boleh menggagalkan penyimpanan pesanan yang memicunya.
 */
class CrmSalesOrderObserver
{
    public function __construct(private OrderNotificationService $notifikasi)
    {
    }

    public function updated(SalesOrder $so): void
    {
        try {
            $this->pembayaran($so);
            $this->siapDiambil($so);
        } catch (\Throwable $e) {
            Log::warning('[CRM] gagal mengantre notifikasi pesanan', [
                'sales_order_id' => $so->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /** Pembayaran bertambah — DP maupun pelunasan. */
    private function pembayaran(SalesOrder $so): void
    {
        if (! $so->wasChanged('paid_amount')) {
            return;
        }

        $sebelum  = (float) $so->getOriginal('paid_amount');
        $sekarang = (float) $so->paid_amount;

        // Hanya kenaikan. Penurunan berarti pembatalan/void — bukan kabar baik
        // yang pantas dikirim ke pelanggan.
        if ($sekarang - $sebelum <= 0.01) {
            return;
        }

        $this->notifikasi->antrekanPembayaranDiterima($so, $sekarang);
    }

    /**
     * Barang siap diambil. 'pending' di kolom pickup_status berarti MENUNGGU
     * DIAMBIL (bukan "belum diproses") — penamaan lama yang mudah salah baca.
     */
    private function siapDiambil(SalesOrder $so): void
    {
        if (! $so->wasChanged('pickup_status') || $so->pickup_status !== 'pending') {
            return;
        }

        $this->notifikasi->antrekanSiapDiambil($so);
    }
}
