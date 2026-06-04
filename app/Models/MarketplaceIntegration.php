<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceIntegration extends Model
{
    protected $fillable = [
        'customer_id',
        'admin_fee_percent',
        'admin_fee_nominal',
        'account_bank_id',
        'account_revenue_id',
        'account_admin_expense_id',
        'account_recon_plus_id',
        'account_recon_minus_id',
        'is_active',
    ];

    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }
}
