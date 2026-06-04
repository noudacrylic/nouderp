<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class NationalHoliday extends Model
{
    protected $table = 'sdm_national_holidays';

    protected $fillable = [
        'tanggal', 'nama', 'is_cuti_bersama', 'catatan', 'created_by',
    ];

    protected $casts = [
        'tanggal'         => 'date',
        'is_cuti_bersama' => 'boolean',
    ];

    public static function isLibur(string $date): bool
    {
        return self::whereDate('tanggal', $date)->exists();
    }
}
