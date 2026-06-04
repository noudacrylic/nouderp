<?php

namespace App\Core\Inventory;

use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model
{
    protected $table = 'stock_ledgers';

    protected $fillable = [
        'date',
        'product_id',
        'warehouse_id',
        'type',
        'reference',
        'qty_in',
        'qty_out',
        'balance',
        'notes'
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
