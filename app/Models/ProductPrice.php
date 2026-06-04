<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $fillable = [
        'product_id',
        'unit_name',
        'channel',
        'price'
    ];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
