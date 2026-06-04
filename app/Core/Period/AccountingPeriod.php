<?php

namespace App\Core\Period;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $fillable = [
        'year',
        'month',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by'
    ];
}
