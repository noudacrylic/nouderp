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
        'harga', 'stok', 'status', 'product_id', 'peta_sumber', 'disinkron_at',
    ];

    protected $casts = [
        'shop_id'      => 'integer',
        'item_id'      => 'integer',
        'model_id'     => 'integer',
        'harga'        => 'decimal:2',
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
}
