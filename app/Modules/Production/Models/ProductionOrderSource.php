<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderSource extends Model
{
    protected $fillable = [
        'production_order_id', 'source_type', 'source_id', 'product_id', 'qty', 'notes',
    ];

    protected $casts = ['qty' => 'decimal:4'];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }

    public function getSourceLabelAttribute(): string
    {
        return match($this->source_type) {
            'warranty'     => 'Garansi',
            'sales_return' => 'Retur Penjualan',
            'stock_repair' => 'Stok Rusak',
            default        => $this->source_type,
        };
    }
}
