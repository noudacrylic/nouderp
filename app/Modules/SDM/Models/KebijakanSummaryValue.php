<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class KebijakanSummaryValue extends Model
{
    protected $table = 'sdm_kebijakan_summary_value';

    protected $fillable = ['summary_id', 'karyawan_id', 'bulan', 'tahun', 'nominal'];

    protected $casts = [
        'summary_id'  => 'integer',
        'karyawan_id' => 'integer',
        'bulan'       => 'integer',
        'tahun'       => 'integer',
        'nominal'     => 'decimal:2',
    ];

    public function summary()
    {
        return $this->belongsTo(KebijakanSummary::class, 'summary_id');
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class, 'karyawan_id');
    }

    /**
     * Resolve nilai untuk satu summary row + karyawan + bulan/tahun.
     * Mengikuti aturan scope & recurrence.
     */
    public static function resolve(KebijakanSummary $summary, int $karyawanId, int $bulan, int $tahun): float
    {
        // mode=auto di-handle di RuleEngine, bukan di sini
        if ($summary->mode === 'auto') return 0.0;

        // scope=all + recurrence=monthly → langsung nominal_manual
        if ($summary->scope === 'all' && $summary->recurrence === 'monthly') {
            return (float) $summary->nominal_manual;
        }

        $q = static::where('summary_id', $summary->id);

        if ($summary->scope === 'per_karyawan') {
            $q->where('karyawan_id', $karyawanId);
        } else {
            $q->whereNull('karyawan_id');
        }

        if ($summary->recurrence === 'one_time') {
            $q->where('bulan', $bulan)->where('tahun', $tahun);
        } else {
            $q->whereNull('bulan')->whereNull('tahun');
        }

        return (float) ($q->value('nominal') ?? 0);
    }
}
