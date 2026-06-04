<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class KaryawanSchedule extends Model
{
    protected $table = 'sdm_karyawan_schedule';

    protected $fillable = [
        'karyawan_id', 'day_of_week',
        'jam_masuk', 'awal_absen_masuk', 'late_in_minutes', 'akhir_absen_masuk',
        'jam_pulang', 'awal_absen_pulang', 'early_out_minutes', 'akhir_absen_pulang',
        'jam_istirahat_start', 'jam_istirahat_end',
        'has_lembur',
        'jam_masuk_lembur', 'awal_absen_lembur_masuk', 'toleransi_lembur_masuk', 'akhir_absen_lembur_masuk',
        'jam_pulang_lembur', 'awal_absen_lembur_pulang', 'toleransi_lembur_pulang', 'akhir_absen_lembur_pulang',
        'jam_istirahat_lembur_start', 'jam_istirahat_lembur_end',
        'is_off',
    ];

    protected $casts = [
        'day_of_week'                => 'integer',
        'late_in_minutes'            => 'integer',
        'early_out_minutes'          => 'integer',
        'awal_absen_masuk'           => 'integer',
        'akhir_absen_masuk'          => 'integer',
        'awal_absen_pulang'          => 'integer',
        'akhir_absen_pulang'         => 'integer',
        'has_lembur'                 => 'boolean',
        'awal_absen_lembur_masuk'    => 'integer',
        'toleransi_lembur_masuk'     => 'integer',
        'akhir_absen_lembur_masuk'   => 'integer',
        'awal_absen_lembur_pulang'   => 'integer',
        'toleransi_lembur_pulang'    => 'integer',
        'akhir_absen_lembur_pulang'  => 'integer',
        'is_off'                     => 'boolean',
    ];

    public const DAYS = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        0 => 'Minggu',
    ];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day_of_week] ?? '-';
    }
}
