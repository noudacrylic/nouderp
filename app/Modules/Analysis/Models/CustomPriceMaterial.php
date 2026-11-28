<?php

namespace App\Modules\Analysis\Models;

use Illuminate\Database\Eloquent\Model;

/** Tebal & kecepatan potong satu bahan lembaran. Ukuran lembar ada di ProductionRawMaterial. */
class CustomPriceMaterial extends Model
{
    protected $table = 'custom_price_materials';

    protected $fillable = ['product_id', 'tebal_mm', 'cut_speed'];

    protected $casts = [
        'tebal_mm'  => 'float',
        'cut_speed' => 'float',
    ];
}
