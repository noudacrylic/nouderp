<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class BomOutput extends Model
{
    protected $fillable = ['bom_id', 'product_id', 'qty_per_cycle', 'output_type', 'percentage', 'notes'];

    protected $casts = [
        'qty_per_cycle' => 'decimal:4',
        'percentage'    => 'decimal:2',
    ];

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
