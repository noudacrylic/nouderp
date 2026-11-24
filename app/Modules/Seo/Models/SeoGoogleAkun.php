<?php

namespace App\Modules\Seo\Models;

use Illuminate\Database\Eloquent\Model;

/** Sambungan OAuth Google Search Console (satu baris) — lihat migrasi 2026_11_25_100000. */
class SeoGoogleAkun extends Model
{
    protected $table = 'seo_google_akun';

    protected $fillable = ['client_id', 'client_secret', 'refresh_token', 'email', 'tersambung_at', 'user_id'];

    protected $casts = ['client_secret' => 'encrypted', 'refresh_token' => 'encrypted', 'tersambung_at' => 'datetime'];

    protected $hidden = ['client_secret', 'refresh_token'];

    public static function satu(): self
    {
        return static::query()->firstOrNew([]);
    }
}
