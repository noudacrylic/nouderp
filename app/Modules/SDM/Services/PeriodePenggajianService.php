<?php

namespace App\Modules\SDM\Services;

use App\Modules\SDM\Models\PeriodePenggajian;
use Carbon\Carbon;

/**
 * Pembuatan periode penggajian/absensi yang dipakai bersama oleh:
 *  • command terjadwal (periode-gaji:ensure-current, tiap tgl 1 jam 00:01)
 *  • tombol manual "Buat Periode Bulan Ini" di halaman Pengaturan Periode
 *  • auto-fill absensi saat scan fingerprint masuk (AdmsService)
 * Satu sumber kebenaran agar field & aturan periode konsisten.
 */
class PeriodePenggajianService
{
    /**
     * Pastikan periode untuk bulan/tahun tersedia (idempotent).
     * Belum ada → buat draft. Sudah ada (status apa pun) → kembalikan apa adanya.
     */
    public function ensureForMonth(int $bulan, int $tahun): PeriodePenggajian
    {
        $start = Carbon::create($tahun, $bulan, 1);

        return PeriodePenggajian::firstOrCreate(
            ['bulan' => $bulan, 'tahun' => $tahun],
            [
                'code'       => sprintf('PG-%04d%02d', $tahun, $bulan),
                'label'      => $start->translatedFormat('F Y'),
                'start_date' => $start->toDateString(),
                'end_date'   => $start->copy()->endOfMonth()->toDateString(),
                'status'     => 'open',
            ]
        );
    }

    /**
     * Periode untuk tanggal scan. Null bila periode bulan itu sudah finalized/void
     * (periode tertutup → jangan diisi otomatis dari scan).
     */
    public function ensureForDate($date): ?PeriodePenggajian
    {
        $c = $date instanceof Carbon ? $date : Carbon::parse($date);
        $periode = $this->ensureForMonth((int) $c->month, (int) $c->year);

        return $periode->status === 'open' ? $periode : null;
    }
}
