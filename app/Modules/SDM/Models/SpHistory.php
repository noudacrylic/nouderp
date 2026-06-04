<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class SpHistory extends Model
{
    protected $table = 'sdm_sp_history';

    protected $fillable = [
        'karyawan_id', 'sanksi', 'tanggal_terbit', 'berlaku_sampai',
        'alasan', 'catatan', 'created_by', 'is_active',
    ];

    protected $casts = [
        'tanggal_terbit' => 'date',
        'berlaku_sampai' => 'date',
        'is_active'      => 'boolean',
    ];

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    public function scopeBerlaku($q, ?string $tanggal = null)
    {
        $tgl = $tanggal ?: now()->toDateString();
        return $q->where('is_active', true)
            ->where('tanggal_terbit', '<=', $tgl)
            ->where(function ($x) use ($tgl) {
                $x->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>=', $tgl);
            });
    }
}
