<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceOverride extends Model
{
    protected $table = 'sdm_attendance_overrides';

    protected $fillable = [
        'karyawan_id', 'tanggal', 'paired_date', 'sesi', 'paired_sesi',
        'jam_masuk_override', 'jam_pulang_override',
        'type', 'notes', 'created_by',
    ];

    protected $casts = [
        'tanggal'     => 'date',
        'paired_date' => 'date',
    ];

    public const TYPES = [
        'cuti'                => 'Cuti',
        'sakit'               => 'Sakit',
        'izin_pagi'           => 'Izin Pagi (datang siang)',
        'izin_sore'           => 'Izin Sore (pulang siang)',
        'tukar_hari'          => 'Tukar Hari',
        'tukar_setengah_hari' => 'Tukar Setengah Hari',
        'penyesuaian_jam'     => 'Penyesuaian Jam',
        'lembur'              => 'Jadwal Lembur',
    ];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
