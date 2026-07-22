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
        'web_order_pin',
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

    /**
     * Alamat lengkap gaya alamat pengiriman: jalan + kelurahan/kecamatan + kota + provinsi + kode pos.
     * Komposisinya identik dengan kartu Pengiriman di form (CustomerController::shippingPayload):
     * jalan dari `shipping_address` (fallback `address`), lalu district, city, province, postal_code.
     * Dipakai di nota cetak (Penawaran/Pesanan/Faktur) supaya formatnya seragam.
     */
    public function fullAddress(): string
    {
        $street = trim((string) ($this->shipping_address ?: $this->address ?: ''));

        return collect([$street, $this->district, $this->city, $this->province, $this->postal_code])
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->implode(', ');
    }
}
