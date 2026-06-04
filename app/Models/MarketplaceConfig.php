<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'admin_fee_percent',
        'admin_fee_fixed',
        'is_active',

        'account_receivable_hold_id',
        'account_fee_id',
        'account_wallet_id',
    ];

    public function holdAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_receivable_hold_id');
    }

    public function feeAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_fee_id');
    }

    public function walletAccount()
    {
        return $this->belongsTo(\App\Core\Accounting\Account::class, 'account_wallet_id');
    }

    protected $casts = [
        'admin_fee_percent' => 'float',
        'admin_fee_fixed' => 'float',
        'is_active' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
