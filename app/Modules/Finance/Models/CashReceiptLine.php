<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use App\Core\Accounting\Account;
use App\Modules\Purchasing\Models\SupplierOverpayment;

class CashReceiptLine extends Model
{
    protected $fillable = [
        'cash_receipt_id',
        'account_id',
        'supplier_overpayment_id',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function receipt()
    {
        return $this->belongsTo(CashReceipt::class, 'cash_receipt_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function supplierOverpayment()
    {
        return $this->belongsTo(SupplierOverpayment::class);
    }
}
