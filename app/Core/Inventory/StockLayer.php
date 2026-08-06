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
        'source_id',
        // Batch finalisasi produksi (nullable) — pembeda antar batch pada satu OP yang
        // hasilnya diambil sebagian, karena source_id dipakai bersama semua batch.
        'production_finalization_id',
    ];
}
