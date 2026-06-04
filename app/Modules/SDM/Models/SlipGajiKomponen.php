<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class SlipGajiKomponen extends Model
{
    protected $table = 'sdm_slip_gaji_komponen';

    protected $fillable = [
        'slip_gaji_id', 'urutan', 'label', 'metode', 'nilai', 'basis', 'amount', 'notes',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'nilai'  => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function slip()
    {
        return $this->belongsTo(SlipGaji::class, 'slip_gaji_id');
    }
}
