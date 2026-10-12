<?php

namespace App\Models;

use App\Modules\Finance\Models\CashDisbursement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Setoran pajak per masa (NTPN), bertaut ke Pengeluaran yang membukukannya. */
class TaxPayment extends Model
{
    public const PPH_FINAL_UMKM = 'pph_final_umkm';

    protected $fillable = [
        'tax_type', 'period', 'paid_at', 'amount', 'ntpn', 'billing_code',
        'cash_disbursement_id', 'via_hutang', 'notes', 'created_by',
    ];

    protected $casts = [
        'period'  => 'date',
        'paid_at' => 'date',
        'amount'  => 'float',
        'via_hutang' => 'boolean',
    ];

    public function scopePphFinal(Builder $q): Builder
    {
        return $q->where('tax_type', self::PPH_FINAL_UMKM);
    }

    public function cashDisbursement()
    {
        return $this->belongsTo(CashDisbursement::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
