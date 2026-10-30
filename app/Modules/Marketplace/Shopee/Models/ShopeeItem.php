<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu varian produk di toko Shopee (model_id 0 = item tanpa varian) beserta petanya ke
 * produk ERP. Peta 'manual' tidak pernah ditimpa tarikan katalog berikutnya.
 */
class ShopeeItem extends Model
{
    public const PETA_SKU    = 'sku';
    public const PETA_MANUAL = 'manual';

    protected $fillable = [
        'env', 'shop_id', 'item_id', 'model_id', 'item_sku', 'model_sku', 'nama', 'nama_model',
        'harga', 'harga_asli', 'stok', 'status', 'product_id', 'peta_sumber', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'      => 'integer',
        'item_id'      => 'integer',
        'model_id'     => 'integer',
        'harga'        => 'decimal:2',
        'harga_asli'   => 'decimal:2',
        'stok'         => 'integer',
        'disinkron_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** SKU yang berlaku: SKU varian, atau SKU item bila tanpa varian. */
    public function sku(): ?string
    {
        return ($this->model_id ? $this->model_sku : null) ?: $this->item_sku ?: null;
    }

    public function namaLengkap(): string
    {
        return $this->nama . ($this->nama_model ? ' — ' . $this->nama_model : '');
    }

    /**
     * Harga Shopee per produk ERP — bentuknya sama dengan JubelioStorePrice::ringkasUntuk()
     * supaya kolom "Di marketplace" di Analisa ▸ Harga bisa memakai keduanya.
     *
     * Yang dibandingkan harga NORMAL (original_price); harga setelah promo ikut sebagai
     * `promo`. Satu produk bisa terjual di beberapa listing/varian: satu angka hanya bila
     * semuanya sepakat, kalau tidak rinciannya per listing (per_toko) yang harus terbaca.
     *
     * @return \Illuminate\Support\Collection<int,array>
     */
    public static function ringkasHarga(ShopeeShop $shop): \Illuminate\Support\Collection
    {
        return static::where('env', $shop->env)->where('shop_id', $shop->shop_id)
            ->where('status', 'NORMAL')->whereNotNull('product_id')
            ->get()
            ->groupBy('product_id')
            ->map(function ($baris) {
                $normal = fn ($r) => $r->harga_asli !== null ? (float) $r->harga_asli : ($r->harga !== null ? (float) $r->harga : null);
                $nilai  = $baris->map($normal)->filter(fn ($v) => $v !== null)->map(fn ($v) => round($v))->unique();
                $promo  = $baris->filter(fn ($r) => $r->harga !== null && $normal($r) !== null && (float) $r->harga < $normal($r) - 0.5)
                    ->map(fn ($r) => (float) $r->harga)->unique();

                return [
                    'price'      => $nilai->count() === 1 ? (float) $nilai->first() : null,
                    'seragam'    => $nilai->count() <= 1,
                    'per_toko'   => $baris->mapWithKeys(fn ($r) => [$r->namaLengkap() => $normal($r)])->all(),
                    'promo'      => $nilai->count() === 1 && $promo->count() === 1 ? $promo->first() : null,
                    'fetched_at' => $baris->min('disinkron_at'),
                    'note'       => null,
                    'sumber'     => 'shopee',
                ];
            });
    }
}
