<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $table = 'business_bank_accounts';

    protected $fillable = [
        'business_profile_id',
        'bank_name',
        'account_number',
        'holder',
        'sort_order',
    ];

    public function profile()
    {
        return $this->belongsTo(BusinessProfile::class, 'business_profile_id');
    }
}
