<?php

namespace App\Modules\Analysis\Services;

use App\Modules\Analysis\Models\ProductionTimeSampleExclusion;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\IsiAntreanCncService;

/**
 * Coret sampel Waktu Produksi yang langkah CNC-nya dikerjakan di mesin lain kelompok:
 * Mesin 1 double head jauh lebih cepat dari Mesin 2 & 3 single head, jadi sampel produk
 * Mesin 1 di Mesin 2/3 (dan sebaliknya) merusak rata-rata waktu & HPP.
 *
 * Dicoret lewat tabel pengecualian yang sama dengan centang manual di halaman Waktu
 * Produksi — tampil tak tercentang beserta alasannya. Keputusan user (26 Okt 2026): selalu
 * dicoret; sampel yang dicentang ulang akan dicoret lagi di pemeriksaan berikutnya.
 */
class SampelMesinService
{
    public const AWALAN_ALASAN = 'Dicoret Manager Produksi';

    /**
     * @return array<int, array{order_id: int, order_number: string, sku: ?string, nama: ?string,
     *                          mesin: string, dicoret: bool, simulasi: bool, reason: string}>
     */
    public function coret(bool $simulasi = false, ?int $olehUserId = null): array
    {
        $sudah = ProductionTimeSampleExclusion::pluck('production_order_id')->flip();

        $orders = ProductionOrder::query()
            ->whereIn('status', ProductionTimeAnalysisService::ELIGIBLE_STATUSES)
            ->whereHas('steps', fn ($q) => $q->where('status', 'completed')
                ->whereHas('department', fn ($d) => $d->where('name', 'like', 'CNC%'))
                ->whereHas('executors', fn ($e) => $e->whereNotNull('parent_executor_id')))
            ->with([
                'steps' => fn ($q) => $q->where('status', 'completed'),
                'steps.department:id,name',
                'steps.executors:id,name,parent_executor_id,kepala_ganda',
                'outputs' => fn ($q) => $q->where('output_type', 'main'),
                'outputs.product:id,sku,name,cnc_kepala_ganda',
            ])
            ->orderBy('id')
            ->get();

        $hasil = [];
        foreach ($orders as $op) {
            if (isset($sudah[$op->id]) || $op->outputs->count() !== 1) continue;
            $p     = $op->outputs->first()->product;
            $mesin = ProductionTimeAnalysisService::mesinTakSesuai($op->steps, (bool) $p?->cnc_kepala_ganda);
            if (!$mesin) continue;

            $kelompok = IsiAntreanCncService::labelKelompok(IsiAntreanCncService::kelompokProduk($p));
            $alasan   = self::AWALAN_ALASAN . ": CNC dikerjakan di {$mesin}, produk kelompok {$kelompok}.";
            if (!$simulasi) {
                ProductionTimeSampleExclusion::updateOrCreate(
                    ['production_order_id' => $op->id],
                    ['reason' => $alasan, 'excluded_by' => $olehUserId],
                );
            }
            $hasil[] = [
                'order_id' => $op->id, 'order_number' => $op->order_number, 'product_id' => $p?->id,
                'sku' => $p?->sku, 'nama' => $p?->name, 'mesin' => $mesin,
                'dicoret' => !$simulasi, 'simulasi' => $simulasi, 'reason' => $alasan,
            ];
        }

        return $hasil;
    }
}
