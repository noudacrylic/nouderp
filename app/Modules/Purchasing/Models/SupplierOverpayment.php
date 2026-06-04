<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Supplier;

class SupplierOverpayment extends Model
{
    protected $fillable = [
        'supplier_id',
        'amount',
        'reference',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
