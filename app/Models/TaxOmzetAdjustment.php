<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Omzet per masa yang tidak tercatat sebagai faktur ERP (mis. sebelum ERP dipakai). */
class TaxOmzetAdjustment extends Model
{
    protected $fillable = ['tax_type', 'period', 'amount', 'notes', 'created_by'];

    protected $casts = [
        'period' => 'date',
        'amount' => 'float',
    ];

    public function scopePphFinal(Builder $q): Builder
    {
        return $q->where('tax_type', TaxPayment::PPH_FINAL_UMKM);
    }
}
