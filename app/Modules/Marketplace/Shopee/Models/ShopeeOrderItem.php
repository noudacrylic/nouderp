<?php

namespace App\Modules\Marketplace\Shopee\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopeeOrderItem extends Model
{
    protected $fillable = [
        'shopee_order_id', 'item_id', 'model_id', 'sku', 'nama', 'nama_model', 'qty',
        'harga_asli', 'harga_diskon', 'product_id',
    ];

    protected $casts = [
        'item_id'      => 'integer',
        'model_id'     => 'integer',
        'qty'          => 'integer',
        'harga_asli'   => 'decimal:2',
        'harga_diskon' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopeeOrder::class, 'shopee_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
