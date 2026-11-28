<?php

namespace App\Modules\Analysis\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu jenis produk custom (Kotak, Tempat Obat, …) beserta angka-angka yang jarang diubah. */
class CustomPriceCategory extends Model
{
    protected $table = 'custom_price_categories';

    protected $fillable = [
        'code', 'name', 'setup_cost', 'markup_percent', 'waste_percent', 'min_order_enabled',
        'cnc_department_id', 'assembly_department_id', 'params',
        'cnc_factor_manual', 'assembly_factor_manual',
    ];

    protected $casts = [
        'setup_cost'             => 'float',
        'markup_percent'         => 'float',
        'waste_percent'          => 'float',
        'min_order_enabled'      => 'boolean',
        'params'                 => 'array',
        'cnc_factor_manual'      => 'float',
        'assembly_factor_manual' => 'float',
    ];

    public function samples()
    {
        return $this->hasMany(CustomPriceSample::class, 'category_id');
    }
}
