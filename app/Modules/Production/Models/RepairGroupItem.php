<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

class RepairGroupItem extends Model
{
    protected $fillable = ['repair_group_id', 'product_id', 'qty'];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    public function group()
    {
        return $this->belongsTo(RepairGroup::class, 'repair_group_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
