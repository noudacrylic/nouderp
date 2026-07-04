<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Baris mutasi rekening koran (bank statement) yang di-upload dari Excel.
 * Dipakai untuk mencocokkan otomatis dengan transaksi ERP saat rekonsiliasi.
 * amount bertanda: + uang masuk, - uang keluar (sudut pandang perusahaan).
 */
class BankStatementLine extends Model
{
    protected $fillable = [
        'bank_reconciliation_id',
        'statement_date',
        'amount',
        'description',
        'matched_journal_line_id',
        'source_row',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'amount'         => 'decimal:2',
    ];

    public function reconciliation()
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }
}
