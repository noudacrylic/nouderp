<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class StockLayer extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'qty_in',
        'qty_remaining',
        'unit_cost',
        'source_type',
        'source_id'
    ];
}
