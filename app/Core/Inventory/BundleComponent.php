<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class BundleComponent extends Model
{
    protected $fillable = [
        'bundle_product_id',
        'component_product_id',
        'qty'
    ];

    public function component()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function componentProduct()
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function bundle()
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }
}
