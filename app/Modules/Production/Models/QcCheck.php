<?php

namespace App\Modules\Production\Models;

use App\Core\Inventory\Product;
use Illuminate\Database\Eloquent\Model;

/** Satu penimbangan bungkus (atau pelepasan QC oleh admin). */
class QcCheck extends Model
{
    public const LULUS   = 'lulus';
    public const KURANG  = 'kurang';
    public const LEBIH   = 'lebih';
    public const DILEPAS = 'dilepas';

    protected $fillable = [
        'production_order_id', 'product_id', 'qc_profile_id', 'mode', 'qty', 'target_gram', 'tolerance_gram',
        'actual_gram', 'diff_gram', 'result', 'analysis', 'repair_status', 'repaired_by_check_id',
        'source', 'raw_frame', 'reason', 'user_id',
    ];

    protected $casts = [
        'qty'            => 'integer',
        'target_gram'    => 'float',
        'tolerance_gram' => 'float',
        'actual_gram'    => 'float',
        'diff_gram'      => 'float',
        'analysis'       => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function lulus(): bool
    {
        return in_array($this->result, [self::LULUS, self::DILEPAS], true);
    }
}
