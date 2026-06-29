<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan Cloudflare R2 — singleton (id=1). Penyimpanan media etalase
 * (foto/video Produk Store). Dikelola di Settings → Integrasi → Cloudflare R2.
 *
 * Kredensial DIPUSATKAN di DB (bukan .env). secret_access_key terenkripsi.
 * StoreMediaService membangun disk on-the-fly dari diskConfig() saat aktif;
 * bila tidak aktif, media jatuh ke disk lokal 'public'.
 */
class R2Setting extends Model
{
    protected $fillable = [
        'is_active', 'access_key_id', 'secret_access_key',
        'bucket', 'endpoint', 'public_url', 'region', 'use_path_style',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'use_path_style'    => 'boolean',
        'secret_access_key' => 'encrypted',
    ];

    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1]);
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function isConfigured(): bool
    {
        return $this->is_active
            && !empty($this->access_key_id)
            && !empty($this->secret_access_key)
            && !empty($this->bucket)
            && !empty($this->endpoint)
            && !empty($this->public_url);
    }

    /** Config disk Flysystem S3 untuk Storage::build(). */
    public function diskConfig(): array
    {
        return [
            'driver'                  => 's3',
            'key'                     => $this->access_key_id,
            'secret'                  => $this->secret_access_key,
            'region'                  => $this->region ?: 'auto',
            'bucket'                  => $this->bucket,
            'endpoint'                => $this->endpoint,
            'url'                     => $this->public_url,
            'use_path_style_endpoint' => (bool) $this->use_path_style,
            'visibility'              => 'public',
            'throw'                   => false,
        ];
    }
}
