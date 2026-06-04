<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{
    protected $table = 'product_stocks';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'qty_on_hand'
    ];

    public function warehouse()
    {
        return $this->belongsTo(
            Warehouse::class,
            'warehouse_id'
        );
    }

    public function product()
    {
        return $this->belongsTo(
            \App\Core\Inventory\Product::class,
            'product_id'
        );
    }
}
