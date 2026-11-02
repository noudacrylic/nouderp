<?php

namespace App\Modules\Galeri\Models;

use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaleriFoto extends Model
{
    protected $table = 'galeri_foto';

    protected $fillable = ['path', 'sales_order_id', 'user_id'];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
