<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintLog extends Model
{
    protected $table = 'sdm_fingerprint_logs';

    protected $fillable = [
        'machine_id', 'karyawan_id', 'user_id_fingerprint',
        'scan_at', 'verify_method', 'verify_type', 'raw_payload',
        'processed', 'processed_at', 'attendance_id',
    ];

    protected $casts = [
        'scan_at'      => 'datetime',
        'raw_payload'  => 'array',
        'processed'    => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(FingerprintMachine::class, 'machine_id');
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }
}
