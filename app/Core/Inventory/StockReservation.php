<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'sales_order_id',
        'qty',
        'status'
    ];
}
