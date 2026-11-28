<?php

namespace App\Modules\Analysis\Models;

use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu OP selesai yang ukurannya diketahui — bahan kalibrasi faktor waktu.
 *
 * Yang disimpan hanya ukurannya (input resep) dan jumlah unitnya. Waktunya TIDAK disalin:
 * dibaca dari timer setiap kali, supaya koreksi timer di OP ikut terbawa.
 */
class CustomPriceSample extends Model
{
    protected $table = 'custom_price_samples';

    protected $fillable = [
        'category_id', 'production_order_id', 'units', 'input', 'excluded', 'exclude_reason', 'notes',
    ];

    protected $casts = [
        'units'    => 'float',
        'input'    => 'array',
        'excluded' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(CustomPriceCategory::class, 'category_id');
    }

    public function order()
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }
}
