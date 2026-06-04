<?php

namespace App\Modules\SDM\Models;

use Illuminate\Database\Eloquent\Model;

class KebijakanKolom extends Model
{
    protected $table = 'sdm_kebijakan_kolom';

    protected $fillable = ['key', 'label', 'tipe', 'urutan', 'is_system', 'is_active'];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'urutan'    => 'integer',
    ];

    public const TIPE = [
        'rupiah' => 'Rupiah',
        'persen' => 'Persen',
        'flag'   => 'Flag / Tanda',
    ];

    public function scopeAktif($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('urutan')->orderBy('id');
    }
}
