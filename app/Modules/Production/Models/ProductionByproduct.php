<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master daftar produk sampingan (by-product) + persentase biaya per unit
 * (basis 1 siklus produksi). Membatasi pilihan output sampingan di BOM/OP.
 */
class ProductionByproduct extends Model
{
    protected $table = 'production_byproducts';

    protected $fillable = ['product_id', 'percentage'];

    protected $casts = [
        'percentage' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
