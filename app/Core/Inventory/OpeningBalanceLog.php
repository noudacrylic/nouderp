<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceLog extends Model
{
    protected $fillable = [
        'date',
        'product_id',
        'warehouse_id',
        'unit_name',
        'qty',
        'unit_cost'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
