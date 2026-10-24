<?php

namespace App\Modules\KaryawanVirtual\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu entri jurnal kerja: satu kali skill bekerja DAN ada tindakan (atau
 * dijalankan manual). Pemeriksaan rutin tanpa tindakan tidak dicatat di sini —
 * cukup di KaryawanVirtualSkill::rincian_terakhir.
 */
class KaryawanVirtualJurnal extends Model
{
    protected $table = 'karyawan_virtual_jurnal';

    public const UPDATED_AT = null;

    protected $fillable = [
        'karyawan', 'skill', 'mode', 'pemicu', 'keterangan_pemicu', 'user_id',
        'ringkasan', 'jumlah_tindakan', 'jumlah_diperiksa', 'rincian',
    ];

    protected $casts = [
        'rincian'          => 'array',
        'jumlah_tindakan'  => 'integer',
        'jumlah_diperiksa' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
