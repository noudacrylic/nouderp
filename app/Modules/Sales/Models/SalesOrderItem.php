<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id',
        'product_id',
        'description',
        'qty',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_per_unit',
        'net_unit_price',
        'line_subtotal',
        'line_discount',
        'line_total',
        'unit_id',
        'unit_name',
        'conversion_to_base',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
