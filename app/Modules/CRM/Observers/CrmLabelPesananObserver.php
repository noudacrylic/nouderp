<?php

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Services\LabelPesananService;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemicu label otomatis CRM Tahap 3. SATU observer untuk semua dokumen yang
 * menggeser tahapan pesanan: SO, pembayaran (SalesAdvance), order produksi,
 * surat jalan, dan tautan marketplace Jubelio.
 *
 * Isinya cuma "pesanan mana yang berubah" — keputusan labelnya di
 * LabelPesananService, yang menghitung dari keadaan pesanan, jadi urutan
 * peristiwa yang datang tidak berpengaruh.
 */
class CrmLabelPesananObserver
{
    /**
     * SO yang lahir di luar chat (modul Sales, kasir) ikut tertaut ke chat
     * pelanggannya. Hanya saat lahir: tautan yang kemudian DILEPAS tangan
     * tidak boleh tersambung lagi sendiri. Event `saved` sesudahnya yang
     * menghitung labelnya.
     */
    public function created(Model $model): void
    {
        if (! $model instanceof SalesOrder) {
            return;
        }

        try {
            app(LabelPesananService::class)->tautkanOtomatis($model);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function saved(Model $model): void
    {
        $soId = $model instanceof SalesOrder ? $model->id : $model->getAttribute('sales_order_id');

        app(LabelPesananService::class)->perbaruiUntukSo($soId ? (int) $soId : null);
    }
}
