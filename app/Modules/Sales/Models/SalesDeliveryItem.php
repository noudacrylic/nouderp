<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class SalesDeliveryItem extends Model
{
    protected $fillable = [
        'sales_delivery_id',
        'sales_order_item_id',
        'product_id',
        'qty',
        'cogs_total',
        'cogs_deferred',
    ];

    protected $casts = [
        'cogs_deferred' => 'boolean',
    ];

    public function salesOrderItem()
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class, 'product_id');
    }
}
