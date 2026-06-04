<?php

namespace App\Core\Journal;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_id',
        'account_id',
        'customer_id',
        'debit',
        'credit',
        'description',
        'reference_type',
        'reference_id',
        'reference_number',
    ];

    public function journal()
    {
        return $this->belongsTo(\App\Core\Journal\Journal::class);
    }

    public function account()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_id');
    }
}
