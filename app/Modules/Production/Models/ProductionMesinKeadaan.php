<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;

/** Potongan keadaan mesin CNC dari sinyal pengontrol — lihat PantauMesinService. */
class ProductionMesinKeadaan extends Model
{
    protected $table = 'production_mesin_keadaan';

    protected $guarded = [];

    protected $casts = [
        'mulai_at'   => 'datetime',
        'sampai_at'  => 'datetime',
        'dilihat_at' => 'datetime',
        'putusan_at' => 'datetime',
    ];

    public function mesin()
    {
        return $this->belongsTo(DepartmentExecutor::class, 'executor_id');
    }
}
