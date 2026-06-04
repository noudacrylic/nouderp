<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class StockShipment extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'delivery_id',
        'qty'
    ];
}
