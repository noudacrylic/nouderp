<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Inventory\Product;
use App\Core\Inventory\Warehouse;

class OpeningBalanceItem extends Model
{
    protected $fillable = ['opening_balance_id', 'product_id', 'warehouse_id', 'qty', 'cost'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
