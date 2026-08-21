<?php

namespace App\Modules\Analysis\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Biaya packing per unit khusus sebuah produk — menimpa angka rata-rata dari Fixed Cost.
 *
 * Hanya berisi pengecualian: produk tanpa baris di sini memakai angka rata-rata.
 */
class ProductPackingCost extends Model
{
    protected $table = 'production_product_packing_costs';

    protected $fillable = ['product_id', 'amount_per_unit', 'notes', 'updated_by'];

    protected $casts = [
        'amount_per_unit' => 'decimal:2',
    ];

    /** @return array<int,float> [product_id => rupiah per unit] */
    public static function perUnitMap(): array
    {
        return static::query()
            ->pluck('amount_per_unit', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
