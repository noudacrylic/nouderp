<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Department;
use App\Modules\Production\Models\ProductionOrderStep;
use Carbon\Carbon;

/**
 * Antrean pekerjaan per divisi & jam kerja — dipakai skill pengisi antrean
 * (Assembling: NmDariBnmService, CNC: IsiAntreanCncService).
 */
class AntreanProduksiService
{
    /** Jam kerja pengisi: Senin–Sabtu 08:00–16:00 (malam/libur antrean pasti kosong). */
    public const JAM_KERJA_HARI    = [1, 2, 3, 4, 5, 6];
    public const JAM_KERJA_MULAI   = '08:00';
    public const JAM_KERJA_SELESAI = '16:00';

    public function jamKerja(Carbon $saat): bool
    {
        return in_array($saat->dayOfWeekIso, self::JAM_KERJA_HARI, true)
            && $saat->format('H:i') >= self::JAM_KERJA_MULAI
            && $saat->format('H:i') < self::JAM_KERJA_SELESAI;
    }

    /** Departemen produksi aktif yang namanya diawali $awalan (mis. 'Assembl', 'CNC'). */
    public function departemen(string $awalan): ?Department
    {
        return Department::where('name', 'like', $awalan . '%')->where('is_active', true)->first();
    }

    /**
     * Jumlah pekerjaan di antrean divisi, definisi sama dengan halaman Proses Produksi
     * (ProductionProcessController::index): langkah pending yang langkah sebelumnya
     * sudah selesai, sedang dikerjakan, atau dijeda — di OP confirmed/in_progress/partial.
     */
    public function jumlah(Department $dept): int
    {
        return ProductionOrderStep::with('productionOrder.steps')
            ->where('department_id', $dept->id)
            ->whereIn('status', ['pending', 'in_progress', 'paused'])
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress', 'partial']))
            ->get()
            ->filter(function ($step) {
                if ($step->status !== 'pending') return true;
                $prev = $step->productionOrder->steps->firstWhere('step_number', $step->step_number - 1);
                return $prev === null || $prev->status === 'completed';
            })
            ->count();
    }
}
