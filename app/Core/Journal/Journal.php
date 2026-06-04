<?php

namespace App\Core\Journal;

use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    protected $fillable = [
        'journal_number',
        'date',
        'reference_type',
        'reference_id',
        'is_initial_balance',
        'reference_number',
        'description',
        'period_id',
        'status',
        'posted_at',
        'voided_at',
        'created_by'
    ];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }
}
