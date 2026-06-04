<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'movement_type',
        'type',
        'reference_type',
        'reference_id',
        'qty'
    ];
}
