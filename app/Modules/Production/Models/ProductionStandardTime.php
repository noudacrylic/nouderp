<?php

namespace App\Modules\Production\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;

/** Waktu standar (menit/pcs) satu SKU di satu divisi, berlaku mulai effective_from. */
class ProductionStandardTime extends Model
{
    protected $fillable = [
        'department_id', 'product_id', 'minutes_per_unit', 'source', 'sample_count',
        'suggested_minutes', 'effective_from', 'notes', 'approved_by',
    ];

    protected $casts = [
        'minutes_per_unit'  => 'float',
        'suggested_minutes' => 'float',
        'effective_from'    => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function approver()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }
}
