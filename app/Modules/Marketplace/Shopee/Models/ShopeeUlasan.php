<?php

namespace App\Modules\Marketplace\Shopee\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu ulasan pembeli Shopee (salinan product/get_comment, lihat ShopeeUlasanService::tarik).
 */
class ShopeeUlasan extends Model
{
    protected $table = 'shopee_ulasan';

    /** Bintang segini ke bawah = ulasan jelek → kartu "Balas ulasan" di Meja Layanan. */
    public const BINTANG_JELEK = 3;

    protected $fillable = [
        'env', 'shop_id', 'comment_id', 'order_sn', 'item_id', 'model_id', 'buyer_username', 'rating_star',
        'comment', 'media', 'editable', 'hidden', 'balasan', 'dibalas_at', 'dibalas_oleh', 'dibuat_shopee_at',
        'draf_balasan', 'draf_chat', 'draf_at', 'draf_galat', 'raw', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'          => 'integer',
        'comment_id'       => 'integer',
        'item_id'          => 'integer',
        'model_id'         => 'integer',
        'rating_star'      => 'integer',
        'media'            => 'array',
        'hidden'           => 'boolean',
        'dibalas_at'       => 'datetime',
        'dibuat_shopee_at' => 'datetime',
        'draf_at'          => 'datetime',
        'raw'              => 'array',
        'disinkron_at'     => 'datetime',
    ];

    /** Ulasan jelek yang belum dibalas toko. */
    public function scopePerluDibalas(Builder $q): Builder
    {
        return $q->where('rating_star', '<=', self::BINTANG_JELEK)->whereNull('balasan')->where('hidden', false);
    }

    /** Pembeli masih bisa mengubah ulasannya — layak diajak chat menyelesaikan masalah. */
    public function bisaDiubahPembeli(): bool
    {
        return $this->editable === 'EDITABLE';
    }

    /** @return array<int, string> URL foto ulasan */
    public function fotoUrl(): array
    {
        // Bentuk dari Shopee: {"image_url_list": [...], "video_url_list": [...]} (kosong = []).
        return collect($this->media['image_url_list'] ?? [])
            ->filter(fn ($u) => is_string($u) && str_starts_with($u, 'http'))
            ->values()->all();
    }

    public function bintang(): string
    {
        return str_repeat('⭐', max(1, $this->rating_star));
    }
}
