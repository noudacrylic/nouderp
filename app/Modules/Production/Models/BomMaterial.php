<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class BomMaterial extends Model
{
    protected $fillable = ['bom_id', 'product_id', 'qty_per_cycle', 'unit', 'notes'];

    protected $casts = ['qty_per_cycle' => 'decimal:4'];

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
