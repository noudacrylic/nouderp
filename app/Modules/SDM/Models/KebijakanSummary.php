<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class KebijakanSummary extends Model
{
    protected $table = 'sdm_kebijakan_summary';

    protected $fillable = [
        'key', 'label', 'urutan', 'mode', 'nominal_manual', 'arah',
        'scope', 'recurrence',
        'is_system', 'is_active',
    ];

    protected $casts = [
        'is_system'      => 'boolean',
        'is_active'      => 'boolean',
        'urutan'         => 'integer',
        'nominal_manual' => 'decimal:2',
    ];

    public const MODES = [
        'manual' => 'Nominal Manual',
        'auto'   => 'Auto dari Rule',
    ];

    public const ARAH = [
        'plus'  => 'Ditambahkan (+)',
        'minus' => 'Potongan (−)',
    ];

    public const SCOPES = [
        'all'          => 'Semua Karyawan',
        'per_karyawan' => 'Per Karyawan',
    ];

    public const RECURRENCES = [
        'monthly'  => 'Tiap Bulan',
        'one_time' => 'Bulan Tertentu',
    ];

    public const KEY_TOTAL = 'total_dibayarkan';

    public function scopeAktif($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('urutan')->orderBy('id');
    }

    public function values()
    {
        return $this->hasMany(KebijakanSummaryValue::class, 'summary_id');
    }

    /**
     * Apakah baris ini butuh input nominal di Summary Absensi (per-karyawan atau one_time)?
     */
    public function needsPerInstanceValue(): bool
    {
        return $this->mode === 'manual'
            && ($this->scope === 'per_karyawan' || $this->recurrence === 'one_time');
    }
}
