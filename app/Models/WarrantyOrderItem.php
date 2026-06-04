<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;

class WarrantyOrderItem extends Model
{
    protected $fillable = [
        'warranty_order_id', 'product_id', 'qty', 'issue_description',
    ];

    public function warrantyOrder()
    {
        return $this->belongsTo(WarrantyOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
