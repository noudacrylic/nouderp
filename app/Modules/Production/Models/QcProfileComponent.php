<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/** Komponen dalam satu bungkus (mis. "Samping" × 2) beserta berat satuannya dari sampel. */
class QcProfileComponent extends Model
{
    protected $fillable = ['qc_profile_id', 'name', 'qty', 'unit_gram', 'samples', 'sort_order'];

    protected $casts = [
        'qty'       => 'integer',
        'unit_gram' => 'float',
        'samples'   => 'array',
    ];
}
