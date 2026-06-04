<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'code',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'npwp',
        'tax_address',
        'payment_term_days',
        'bank_name',
        'bank_account_no',
        'bank_account_name',
        'account_payable_id',
        'account_dp_id',
        'is_active',
    ];

    public function purchaseOrders()
    {
        return $this->hasMany(\App\Modules\Purchasing\Models\PurchaseOrder::class);
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(\App\Modules\Purchasing\Models\PurchaseInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(\App\Modules\Purchasing\Models\SupplierPayment::class);
    }
}
