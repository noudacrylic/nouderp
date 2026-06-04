<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    protected $table = 'product_units';

    protected $fillable = [
        'product_id',
        'unit_name',
        'conversion_to_base',
        'level'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
