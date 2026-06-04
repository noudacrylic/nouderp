<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'sdm_attendance';

    protected $fillable = [
        'periode_id', 'karyawan_id', 'tanggal', 'week', 'shift_name',
        'on_work1', 'off_work1', 'on_work2', 'off_work2', 'on_work3', 'off_work3',
        'absent_days', 'working_hours', 'ot_hours', 'late_minutes',
        'early_out_minutes', 'absent_punch_times', 'public_holiday_hours', 'leave_hours', 'remark',
        'status', 'overtime_hours',
        'get_full_pay', 'get_half_pay', 'get_tunjangan', 'get_bonus_absen',
        'edited_manually',
    ];

    protected $casts = [
        'tanggal'         => 'date',
        'absent_days'     => 'decimal:2',
        'working_hours'   => 'decimal:2',
        'ot_hours'        => 'decimal:2',
        'overtime_hours'  => 'decimal:2',
        'public_holiday_hours' => 'decimal:2',
        'leave_hours'     => 'decimal:2',
        'get_full_pay'    => 'boolean',
        'get_half_pay'    => 'boolean',
        'get_tunjangan'   => 'boolean',
        'get_bonus_absen' => 'boolean',
        'edited_manually' => 'boolean',
    ];

    public function periode()
    {
        return $this->belongsTo(PeriodePenggajian::class, 'periode_id');
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }
}
