<?php

namespace App\Modules\Tasks\MejaKerja\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anggota meja peran: user yang membuka & mengambil task meja itu (urutan hanya urutan tampil). */
class MejaKerjaPeran extends Model
{
    public const PERAN = [
        'layanan' => 'Layanan & Operasional',
        'desain'  => 'Desain & Konten',
        'owner'   => 'Owner',
    ];

    protected $table = 'meja_kerja_peran';

    protected $fillable = ['peran', 'user_id', 'urutan'];

    protected $casts = ['urutan' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
