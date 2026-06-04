<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerOverpayment extends Model
{
    protected $fillable = [
        'customer_id',
        'amount',
        'reference',
        'note'
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
