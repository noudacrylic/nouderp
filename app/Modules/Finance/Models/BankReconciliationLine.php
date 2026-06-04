<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Journal\JournalLine;

class BankReconciliationLine extends Model
{
    protected $fillable = [
        'bank_reconciliation_id',
        'journal_line_id',
        'is_matched',
        'statement_date',
        'note',
    ];

    protected $casts = [
        'is_matched' => 'boolean',
        'statement_date' => 'date',
    ];

    public function reconciliation()
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function journalLine()
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
