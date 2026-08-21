<?php

namespace App\Modules\Analysis\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * Harga andaian sebuah bahan baku. Kosong = bahan itu dipakai dengan harga beli terakhirnya.
 */
class MaterialPriceAssumption extends Model
{
    protected $fillable = ['product_id', 'price', 'updated_by'];

    protected $casts = ['price' => 'float'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return array<int,float> harga asumsi dikunci product_id */
    public static function map(): array
    {
        return static::pluck('price', 'product_id')->map(fn ($v) => (float) $v)->all();
    }
}
