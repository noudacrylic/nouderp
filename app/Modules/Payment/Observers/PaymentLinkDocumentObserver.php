<?php

namespace App\Modules\Payment\Observers;

use App\Models\SalesInvoice;
use App\Modules\Payment\Services\PaymentLinkService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dokumen mati → tautan pembayarannya ikut mati.
 *
 * Dipasang sebagai observer (bukan dipanggil dari controller) supaya SEMUA jalur
 * pembatalan tertutup: tombol Void/Hapus di ERP, pembatalan otomatis pesanan web yang
 * lewat batas waktu, void dari sinkronisasi marketplace, sampai perintah artisan.
 * Menambah satu jalur baru di kemudian hari tidak perlu ingat memanggil apa pun.
 */
class PaymentLinkDocumentObserver
{
    /** Status yang berarti dokumen tidak boleh dibayar lagi. */
    private const DEAD = ['void', 'cancelled', 'canceled'];

    public function __construct(protected PaymentLinkService $links)
    {
    }

    public function updated(Model $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        $status = strtolower((string) ($model->status instanceof \BackedEnum ? $model->status->value : $model->status));
        if (! in_array($status, self::DEAD, true)) {
            return;
        }

        $this->kill($model);
    }

    /**
     * Sengaja `deleting`, bukan `deleted`: kolom sales_order_id/sales_invoice_id di
     * midtrans_transactions memakai FK `nullOnDelete`, jadi begitu dokumennya benar-benar
     * terhapus, transaksinya sudah kehilangan rujukan dan tidak bisa ditemukan lagi.
     */
    public function deleting(Model $model): void
    {
        $this->kill($model);
    }

    private function kill(Model $model): void
    {
        if ($model instanceof SalesInvoice) {
            $this->links->deactivateForInvoice($model->id);

            return;
        }

        if ($model instanceof SalesOrder) {
            $this->links->deactivateForSalesOrder($model->id);
        }
    }
}
