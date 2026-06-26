<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Jejak satu pengingat absensi terkirim (karyawan + tanggal + type), unik —
 * dipakai AttendanceReminderService untuk idempotensi (tak spam tiap run cron).
 */
class AttendanceReminder extends Model
{
    protected $table = 'sdm_attendance_reminders';

    protected $fillable = ['karyawan_id', 'tanggal', 'type'];

    protected $casts = ['tanggal' => 'date'];
}
