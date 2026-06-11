<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionSetting extends Model
{
    protected $table = 'production_settings';

    protected $fillable = [
        'score_sales_period',
        'score_period_mode',
        'score_period_start',
        'score_period_end',
    ];

    protected $casts = [
        'score_sales_period' => 'integer',
        'score_period_start' => 'date',
        'score_period_end'   => 'date',
    ];

    public static function getSalesPeriod(): int
    {
        $setting = static::first();
        return $setting ? (int) $setting->score_sales_period : 1;
    }

    /**
     * Simpan pengaturan periode. Mode:
     *   'week'   → 7 hari terakhir
     *   'months' → $salesPeriod bulan terakhir (1/2/3)
     *   'range'  → rentang tanggal tetap [$start, $end]
     */
    public static function savePeriod(string $mode, int $salesPeriod = 1, ?string $start = null, ?string $end = null): void
    {
        $setting = static::first() ?? new static();
        $setting->score_period_mode  = $mode;
        $setting->score_sales_period = max(1, $salesPeriod);
        $setting->score_period_start = $mode === 'range' ? $start : null;
        $setting->score_period_end   = $mode === 'range' ? $end : null;
        $setting->save();
    }

    /** (Lama) — dipertahankan untuk kompatibilitas. */
    public static function upsert(int $salesPeriod): void
    {
        static::savePeriod('months', $salesPeriod);
    }
}
