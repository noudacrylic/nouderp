<?php

namespace App\Modules\Analysis\Models;

use App\Modules\Production\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * OP yang dikecualikan operator dari sampel analisa waktu produksi.
 * Lihat migration 2026_07_31_100000 untuk alasan pola opt-out.
 */
class ProductionTimeSampleExclusion extends Model
{
    protected $table = 'production_time_sample_exclusions';

    protected $fillable = ['production_order_id', 'reason', 'excluded_by'];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function excludedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'excluded_by');
    }
}
