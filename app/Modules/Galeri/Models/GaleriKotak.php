<?php

namespace App\Modules\Galeri\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu berkas di Kotak Porto — lihat migrasi 2026_11_17_100000. */
class GaleriKotak extends Model
{
    protected $table = 'galeri_kotak';

    protected $fillable = ['user_id', 'nama', 'berkas', 'jenis', 'ukuran', 'sales_order_id', 'path_nas', 'dipakai_at'];

    protected $casts = ['dipakai_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function abs(): string
    {
        return rtrim(config('galeri.kotak_dir'), '/\\') . '/' . $this->berkas;
    }
}
