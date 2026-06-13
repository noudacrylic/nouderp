<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master daftar produk bahan baku (lembaran) + ukuran lembar (Panjang × Lebar).
 * Dipakai Kalkulator Produk Custom di OP untuk menghitung kebutuhan bahan baku.
 */
class ProductionRawMaterial extends Model
{
    protected $table = 'production_raw_materials';

    protected $fillable = ['product_id', 'panjang', 'lebar'];

    protected $casts = [
        'panjang' => 'decimal:2',
        'lebar'   => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(\App\Core\Inventory\Product::class);
    }
}
