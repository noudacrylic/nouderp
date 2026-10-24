<?php

namespace App\Modules\KaryawanVirtual\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keadaan satu skill: mode + hasil pemeriksaan terakhir. Lihat KaryawanVirtualService. */
class KaryawanVirtualSkill extends Model
{
    protected $table = 'karyawan_virtual_skills';

    protected $fillable = [
        'skill', 'mode', 'mode_diubah_oleh', 'mode_diubah_at',
        'terakhir_jalan_at', 'ringkasan_terakhir', 'rincian_terakhir',
    ];

    protected $casts = [
        'mode_diubah_at'    => 'datetime',
        'terakhir_jalan_at' => 'datetime',
        'rincian_terakhir'  => 'array',
    ];

    public function pengubahMode(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mode_diubah_oleh');
    }
}
