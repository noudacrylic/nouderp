<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'address',
        'shipping_address',
        'city',
        'province',
        'district',
        'postal_code',
        'recipient_phone',
        'biteship_area_id',
        'kiriminaja_area_id',
        'latitude',
        'longitude',
        'customer_type',
        'is_marketplace',
        'marketplace_code',
        'is_active',
        'admin_percent',
        'admin_nominal',
        'account_bank_id',
        'account_revenue_id',
        'account_admin_expense_id',
        'account_recon_plus_id',
        'account_recon_minus_id',
    ];

    protected $appends = [
        'marketplace_hold_name',
        'credit_balance'
    ];

    public function marketplaceIntegration()
    {
        return $this->hasOne(MarketplaceIntegration::class);
    }

    public function marketplace()
    {
        return $this->hasOne(MarketplaceConfig::class);
    }

    public function getMarketplaceHoldNameAttribute()
    {
        if (!$this->is_marketplace) return null;
        $config = $this->marketplace()->with('holdAccount')->first();
        return $config?->holdAccount?->name ?? 'Saldo Ditahan Marketplace';
    }

    public function overpayments()
    {
        return $this->hasMany(CustomerOverpayment::class);
    }

    public function getCreditBalanceAttribute()
    {
        return $this->overpayments_sum_amount ?? 0;
    }
}
