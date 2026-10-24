<?php

namespace App\Modules\KaryawanVirtual\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu usulan skill bermode "Minta persetujuan". Lihat UsulanService. */
class KaryawanVirtualUsulan extends Model
{
    protected $table = 'karyawan_virtual_usulan';

    public const MENUNGGU    = 'menunggu';
    public const DIPROSES    = 'diproses';
    public const DISETUJUI   = 'disetujui';
    public const DITOLAK     = 'ditolak';
    public const KEDALUWARSA = 'kedaluwarsa';
    public const BATAL       = 'batal';
    public const GAGAL       = 'gagal';

    public const LABEL_STATUS = [
        self::MENUNGGU    => 'Menunggu',
        self::DIPROSES    => 'Diproses',
        self::DISETUJUI   => 'Disetujui',
        self::DITOLAK     => 'Ditolak',
        self::KEDALUWARSA => 'Kedaluwarsa',
        self::BATAL       => 'Batal',
        self::GAGAL       => 'Gagal',
    ];

    protected $fillable = [
        'karyawan', 'skill', 'kunci', 'judul', 'alasan', 'data', 'status', 'kedaluwarsa_at',
        'diputus_oleh', 'diputus_at', 'diputus_lewat', 'catatan_hasil', 'dokumen', 'tautan',
        'telegram', 'jurnal_id',
    ];

    protected $casts = [
        'data'           => 'array',
        'telegram'       => 'array',
        'kedaluwarsa_at' => 'datetime',
        'diputus_at'     => 'datetime',
    ];

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh');
    }

    public function scopeMenunggu($query)
    {
        return $query->where('status', self::MENUNGGU);
    }
}
