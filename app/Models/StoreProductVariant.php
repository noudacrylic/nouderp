<?php

namespace App\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;

class StoreProductVariant extends Model
{
    protected $fillable = [
        'store_product_id',
        'product_id',
        'variant_label',
        'option_values',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_default'    => 'boolean',
        'sort_order'    => 'integer',
        'option_values' => 'array',
    ];

    /** Perubahan varian menyentuh updated_at induk → sync cache katalog jalan. */
    protected $touches = ['storeProduct'];

    public function storeProduct()
    {
        return $this->belongsTo(StoreProduct::class);
    }

    /** SKU ERP sumber harga/stok/berat (live, tidak diduplikasi). */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
