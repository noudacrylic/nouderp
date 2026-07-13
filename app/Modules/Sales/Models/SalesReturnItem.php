<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;

class SalesReturnItem extends Model
{
    protected $fillable = [
        'sales_return_id',
        'reference_item_id',
        'product_id',
        'qty',
        'unit_price',
        'subtotal',
        'condition',
        'component_conditions',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'component_conditions' => 'array',
    ];

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
